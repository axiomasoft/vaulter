<?php

declare(strict_types=1);

/*
 * scripts/php84 php scripts/inventory/move-types.php --owner=<drive|media|webhooks|documents|collaboration>
 *     [--rule-file=<name>] [--fqcn-prefix=<Vaulter\X\>]… [--exclude-fqcn=<FQCN>]… [--tests=<old-dir>:<new-dir>]…
 *     [--decision=D40] [--root=<dir>] [--no-git] [--json] (--dry-run | --apply)
 *
 * Moves the types of tests/Repo/Arch/Fixtures/target-map.json (phase=P4, action=move, phase=P5 for the owner collaboration) to the package that owns them:
 * file, namespace, imports, fully qualified names, strings, docs, test directories, suites, zones and the baseline
 * ledger. Exit: 0 done, 1 a failure while reading or applying (nothing was written before the
 * checks), 2 usage, conflict, empty selection or a replace/split type.
 */

use Vaulter\Inventory\Move\MoveFailure;
use Vaulter\Inventory\Move\Mover;
use Vaulter\Inventory\Move\MoveUsage;
use Vaulter\Inventory\Repo;

require_once __DIR__.'/bootstrap.php';
foreach (['Errors', 'FqcnText', 'PhpRewriter', 'Plan', 'TestMover', 'Mover'] as $class) {
    require_once __DIR__.'/lib/Move/'.$class.'.php';
}

function move_usage(string $message): never
{
    fwrite(STDERR, $message."\n".'usage: move-types.php --owner=<drive|media|webhooks|documents|collaboration> [--rule-file=<name>] [--fqcn-prefix=<prefix>]… [--exclude-fqcn=<FQCN>]… [--tests=<old-dir>:<new-dir>]… [--decision=D#] [--root=<dir>] [--no-git] [--json] (--dry-run | --apply)'."\n");
    exit(2);
}

$single = ['owner' => null, 'rule-file' => null, 'decision' => 'D40', 'root' => null];
$multiple = ['fqcn-prefix' => [], 'exclude-fqcn' => [], 'tests' => []];
$flags = ['dry-run' => false, 'apply' => false, 'json' => false, 'no-git' => false];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z][a-z-]*)(?:=(.*))?$/s', $argument, $m) !== 1) {
        move_usage("unknown argument {$argument}");
    }
    [$name, $value] = [$m[1], $m[2] ?? null];
    if (array_key_exists($name, $flags) && $value === null) {
        $flags[$name] = true;
    } elseif (array_key_exists($name, $single) && $value !== null && $value !== '') {
        $single[$name] = $value;
    } elseif (array_key_exists($name, $multiple) && $value !== null && $value !== '') {
        $multiple[$name][] = $value;
    } else {
        move_usage("unknown or incomplete option {$argument}");
    }
}
if ($flags['dry-run'] === $flags['apply']) {
    move_usage('exactly one of --dry-run and --apply is required');
}
if ($single['owner'] === null) {
    move_usage('--owner is required');
}

$repo = $single['root'] === null ? Repo::discover() : Repo::at((string) realpath($single['root']) ?: $single['root']);
try {
    $report = (new Mover($repo, !$flags['no-git']))->run([
        'owner' => $single['owner'],
        'rule_file' => $single['rule-file'],
        'prefixes' => $multiple['fqcn-prefix'],
        'excluded' => $multiple['exclude-fqcn'],
    ], $multiple['tests'], (string) $single['decision'], $flags['apply']);
} catch (MoveUsage $e) {
    move_usage($e->getMessage());
} catch (MoveFailure|Throwable $e) {
    fwrite(STDERR, 'move-types: '.$e->getMessage()."\n");
    exit(1);
}

if ($flags['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

$mode = $flags['apply'] ? 'applied' : 'dry run';
$total = count($report['moved']) + array_sum($report['edits']) + array_sum($report['strings']) + count($report['tests']) + count($report['ledger']);
if ($total === 0) {
    echo "{$mode}: 0 changes\n";
} else {
    printf("%s: %d types moved, %d edits in %d files, %d strings in %d files, %d test directories, %d ledger entries\n",
        $mode, count($report['moved']), array_sum($report['edits']), count($report['edits']), array_sum($report['strings']), count($report['strings']), count($report['tests']), count($report['ledger']));
}
foreach ($report['warnings'] as $warning) {
    echo "warning: {$warning}\n";
}
exit(0);
