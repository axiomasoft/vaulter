<?php

declare(strict_types=1);

/** @return list<string> Exact, consumed allowlist entries only; cycles and unknown siblings cannot be waived. */
function dg_errors(array $manifests, array $allowlist): array
{
    $errors = [];
    $used = [];
    $sibling = static fn (string $name): bool => str_starts_with($name, 'axioma-studio/vaulter-') || $name === 'axioma-studio/vaulter';
    $closure = function (string $name, array $active = []) use (&$closure, $manifests, $sibling): array {
        if (isset($active[$name])) {
            throw new RuntimeException('runtime cycle: '.implode(' -> ', [...array_keys($active), $name]));
        }
        if (!isset($manifests[$name])) {
            throw new RuntimeException('unknown sibling: '.$name);
        }
        $active[$name] = true;
        $visited = [];
        foreach (array_keys($manifests[$name]['require'] ?? []) as $dependency) {
            if ($sibling($dependency)) {
                $visited[$dependency] = true;
                foreach ($closure($dependency, $active) as $lower) {
                    $visited[$lower] = true;
                }
            }
        }

        return array_keys($visited);
    };
    foreach ($manifests as $name => $manifest) {
        try {
            $lower = $closure($name);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();

            continue;
        }
        if ($name === 'axioma-studio/vaulter') {
            continue;
        }
        foreach (array_keys($manifest['require-dev'] ?? []) as $to) {
            if (!$sibling($to) || in_array($to, $lower, true)) {
                continue;
            }
            $matched = false;
            foreach ($allowlist as $i => $entry) {
                if (($entry['from'] ?? '') === $name && ($entry['to'] ?? '') === $to && trim($entry['reason'] ?? '') !== '' && trim($entry['decision'] ?? '') !== '' && isset($manifests[$to])) {
                    $used[$i] = true;
                    $matched = true;
                }
            }
            if (!$matched) {
                $errors[] = 'reverse dev edge: '.$name.' -> '.$to;
            }
        }
    }
    foreach ($allowlist as $i => $entry) {
        if (!isset($used[$i])) {
            $errors[] = 'stale or invalid allowlist: '.$i;
        }
    }

    return $errors;
}

it('keeps package dev siblings within the runtime transitive closure', function (): void {
    $manifests = [];
    foreach (glob(dirname(__DIR__, 3).'/packages/*/composer.json') as $path) {
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $manifests[$manifest['name']] = $manifest;
    }
    $allowlist = json_decode(file_get_contents(__DIR__.'/Fixtures/allowlists/dev-graph.json'), true, flags: JSON_THROW_ON_ERROR);
    expect(dg_errors($manifests, $allowlist))->toBe([]);
});

it('detects reverse dev edges cycles unknown siblings and stale exceptions on fixture manifests', function (): void {
    $cases = json_decode(file_get_contents(__DIR__.'/Fixtures/dev-graph/cases.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($cases as $case) {
        $errors = dg_errors($case['manifests'], $case['allowlist'] ?? []);
        if ($case['error'] === null) {
            expect($errors)->toBe([], $case['name']);
        } else {
            expect(implode("\n", $errors))->toContain($case['error']);
        }
    }
});
