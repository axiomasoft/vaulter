#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Which `Vaulter\...` classes does the PHP code under these directories name? (PLAN3 P4.19, helper of consumer-check.sh)
 *
 *   php consumer-scan.php [--strings] <dir>...      prints "<file>:<line>:<Vaulter\Name\...>" per reference, sorted
 *
 * Names are read from the token stream, so an architecture test that spells the namespace it FORBIDS in a string or in
 * a heredoc fixture is not a reference. `--strings` also counts a string that is, as a whole, a class name
 * ('Vaulter\\Drive\\Models\\Node' as a config default): production code couples to a sibling that way too. Its own
 * `namespace` declaration is not a reference.
 */

$strings = false;
$directories = [];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--strings') {
        $strings = true;
    } else {
        $directories[] = $argument;
    }
}

$found = [];
foreach ($directories as $directory) {
    if (!is_dir($directory)) {
        continue;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $tokens = token_get_all((string) file_get_contents($file->getPathname()));
        $afterNamespace = false;
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }
            [$id, $text, $line] = $token;
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }
            if ($id === T_NAMESPACE) {
                $afterNamespace = true;

                continue;
            }
            $name = null;
            if ($id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
                $name = $afterNamespace ? null : ltrim($text, '\\');
            } elseif ($strings && $id === T_CONSTANT_ENCAPSED_STRING) {
                $value = str_replace('\\\\', '\\', substr($text, 1, -1));
                if (preg_match('/^\\\\?Vaulter(\\\\[A-Za-z0-9_]+)+$/', $value) === 1) {
                    $name = ltrim($value, '\\');
                }
            }
            $afterNamespace = false;
            if ($name !== null && str_starts_with($name, 'Vaulter\\')) {
                $found[$file->getPathname().':'.$line.':'.$name] = true;
            }
        }
    }
}

$lines = array_keys($found);
sort($lines);
foreach ($lines as $line) {
    echo $line, "\n";
}
