<?php

declare(strict_types=1);

/** Pure budget evaluator; boundary scan I1 owns forbidden source namespace enforcement. */
function kb_errors(array $manifest, array $actual, array $budget): array
{
    $errors = [];
    $keys = array_keys($manifest['require'] ?? []);
    $expected = $budget['runtime_require'];
    sort($keys);
    sort($expected);
    if ($keys !== $expected) {
        $errors[] = 'runtime_require';
    }
    foreach (array_keys($manifest['require-dev'] ?? []) as $name) {
        if (str_starts_with($name, $budget['forbidden_dev_packages_prefix'])) {
            $errors[] = 'require-dev';
        }
    }
    foreach (['php_files', 'loc', 'api_types'] as $key) {
        $base = $key === 'api_types' ? $budget[$key] : $budget['src'][$key];
        if ($base * (100 + $budget['src']['max_growth_percent']) < $actual[$key] * 100) {
            $errors[] = $key;
        }
    }
    $history = $budget['history'];
    if ($history === [] || trim(end($history)['reason'] ?? '') === '') {
        $errors[] = 'history reason';
    }

    return array_values(array_unique($errors));
}

it('enforces the measured kernel budget and exact runtime dependency keys', function (): void {
    $root = dirname(__DIR__, 3);
    $budget = json_decode(file_get_contents(__DIR__.'/Fixtures/kernel-budget.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = ['php_files' => 0, 'loc' => 0, 'api_types' => 0];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/packages/core/src', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && !$file->isLink() && $file->getExtension() === 'php') {
            $actual['php_files']++;
            $text = file_get_contents($file->getPathname());
            $lines = preg_split('/\r\n|\n|\r/', $text);
            if (end($lines) === '') {
                array_pop($lines);
            }
            $actual['loc'] += count($lines);
        }
    }
    preg_match_all('/^(Vaulter\\\\\S+) (?:api|spi:)/m', file_get_contents($root.'/packages/core/tests/Arch/__snapshots__/surface.txt'), $types);
    $actual['api_types'] = count(array_unique($types[1]));
    $manifest = json_decode(file_get_contents($root.'/packages/core/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(kb_errors($manifest, $actual, $budget))->toBe([], 'Update kernel-budget.json with a reason and ADR; source isolation is enforced by I1.');
});

it('rejects budget boundary plus one and independent dependency or reason changes', function (): void {
    $budget = ['runtime_require' => ['php'], 'forbidden_dev_packages_prefix' => 'axioma-studio/', 'src' => ['php_files' => 100, 'loc' => 1000, 'max_growth_percent' => 10], 'api_types' => 20, 'history' => [['reason' => 'fixture baseline']]];
    $cases = json_decode(file_get_contents(__DIR__.'/Fixtures/kernel-budget/cases.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($cases as $case) {
        $fixture = $budget;
        $fixture['history'][0]['reason'] = $case['reason'] ?? 'fixture baseline';
        $manifest = ['require' => array_fill_keys($case['require'] ?? ['php'], '*'), 'require-dev' => array_fill_keys($case['dev'] ?? [], '*')];
        expect(kb_errors($manifest, $case['metrics'] ?? ['php_files' => 100, 'loc' => 1000, 'api_types' => 20], $fixture))->toBe($case['errors'], $case['name']);
    }
    expect(kb_errors(['require' => ['b' => '*', 'php' => '*']], ['php_files' => 1, 'loc' => 1, 'api_types' => 1], array_replace($budget, ['runtime_require' => ['php', 'b']])))->toBe([]);
});
