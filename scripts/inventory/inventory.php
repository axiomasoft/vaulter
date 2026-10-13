<?php

declare(strict_types=1);

/*
 * scripts/php84 php scripts/inventory/inventory.php [<name>…] (--write | --check | --report) [--key=value…]
 *
 * Generators are discovered from generators/*.php (name() == basename). Without names every generator runs, in
 * alphabetical order. Exit: 0 all passed, 1 a fixture differs, 2 a rule/usage error (unknown generator, conflict…).
 */

use Vaulter\Inventory\Generator;
use Vaulter\Inventory\InventoryError;
use Vaulter\Inventory\Repo;

require_once __DIR__.'/bootstrap.php';

function inventory_usage(string $message): never
{
    fwrite(STDERR, $message."\n".'usage: inventory.php [<name>…] (--write | --check | --report) [--key=value…]'."\n");
    exit(2);
}

$names = [];
$modes = [];
$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(write|check|report)$/', $argument, $m) === 1) {
        $modes[] = $m[1];
    } elseif (preg_match('/^--([a-z][a-z0-9-]*)=(.*)$/', $argument, $m) === 1) {
        $options[$m[1]] = $m[2];
    } elseif (str_starts_with($argument, '-')) {
        inventory_usage("unknown option {$argument}");
    } else {
        $names[] = $argument;
    }
}
if (count($modes) !== 1) {
    inventory_usage('exactly one of --write, --check, --report is required');
}
$mode = $modes[0];

/** @var array<string, Generator> $generators */
$generators = [];
$files = glob(__DIR__.'/generators/*.php') ?: [];
sort($files, SORT_STRING);
foreach ($files as $file) {
    $generator = require $file;
    if (!$generator instanceof Generator || $generator->name() !== basename($file, '.php')) {
        fwrite(STDERR, basename($file).": must return a Generator whose name() is the file basename\n");
        exit(2);
    }
    $generators[$generator->name()] = $generator;
}

foreach ($names as $name) {
    if (!isset($generators[$name])) {
        inventory_usage("unknown generator {$name} (known: ".implode(', ', array_keys($generators)).')');
    }
}
$selected = $names === [] ? $generators : array_intersect_key($generators, array_flip($names));
ksort($selected, SORT_STRING);

$repo = Repo::discover();
$exit = 0;
foreach ($selected as $name => $generator) {
    try {
        if ($mode === 'write') {
            $outcome = ['code' => 0, 'lines' => array_map(static fn (string $f): string => "wrote {$f}", $generator->write($repo, $options))];
        } else {
            $outcome = $mode === 'check' ? $generator->check($repo, $options) : $generator->report($repo, $options);
        }
    } catch (InventoryError $e) {
        $outcome = ['code' => 2, 'lines' => [$e->getMessage()]];
    }
    foreach ($outcome['lines'] as $line) {
        fwrite($outcome['code'] === 0 ? STDOUT : STDERR, "[{$name}] {$line}\n");
    }
    fwrite(STDOUT, sprintf("[%s] %s\n", $name, $outcome['code'] === 0 ? 'ok' : "exit {$outcome['code']}"));
    $exit = $outcome['code'] === 2 || $exit === 2 ? 2 : max($exit, $outcome['code']);
}

exit($exit);
