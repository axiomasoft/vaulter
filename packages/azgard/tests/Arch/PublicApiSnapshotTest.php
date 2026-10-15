<?php

declare(strict_types=1);
use Vaulter\Attributes\Internal;
use Vaulter\Azgard\AzgardPermissionDriver;

/**
 * VLT-REL P2.2 — executable freeze of vaulter-azgard's public composer surface for the `0.1.0`
 * tag. Counterpart to the P2.1 SSOT (`plans/archive/VLT-REL/root/contracts/public-api-surface.md`):
 * reflects every `Vaulter\\Azgard\\Contracts\**` contract into a canonical manifest and compares it to a
 * committed golden, reddening the build on any unplanned signature change (removed/renamed
 * method, narrowed type, a new contract). Same canonicalisation and AMEND procedure as
 * vaulter-core's PublicApiSnapshotTest (D5 — signatures only; config/resources/bindings
 * are frozen by the doc + `.claude/rules/public-api.md`, not reflection). `UPDATE_SNAPSHOT`
 * is env-gated and NEVER set by CI; regenerating outside AMEND (no D#) is `[POSSIBLE-DEFECT]`.
 */
if (!function_exists('vaulterSnapshotType')) {
    /**
     * Normalise a reflection type to a canonical, deterministic string. Union and
     * intersection members are sorted so a source-level reorder (not a BC change) does
     * not redden the build. Non-builtin types carry a leading `\` for an unambiguous FQCN.
     */
    function vaulterSnapshotType(?ReflectionType $type): string
    {
        if ($type === null) {
            return '';
        }

        if ($type instanceof ReflectionUnionType) {
            $parts = array_map('vaulterSnapshotType', $type->getTypes());
            sort($parts);

            return implode('|', $parts);
        }

        if ($type instanceof ReflectionIntersectionType) {
            $parts = array_map('vaulterSnapshotType', $type->getTypes());
            sort($parts);

            return implode('&', $parts);
        }

        /** @var ReflectionNamedType $type */
        $name = $type->getName();
        $lower = strtolower($name);
        $prefix = ($type->allowsNull() && $lower !== 'null' && $lower !== 'mixed') ? '?' : '';

        return $prefix.($type->isBuiltin() ? '' : '\\').$name;
    }
}

if (!function_exists('vaulterSnapshotValue')) {
    /**
     * Compact, deterministic rendering of a parameter default value (scalars, null,
     * shallow arrays — the only shapes contract defaults take).
     */
    function vaulterSnapshotValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value)) {
            return "'".$value."'";
        }

        if (is_array($value)) {
            return '['.implode(', ', array_map('vaulterSnapshotValue', $value)).']';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return get_debug_type($value);
    }
}

if (!function_exists('vaulterSnapshotMethod')) {
    /** Serialise one public method to `name(type $p = default, …): return`. */
    function vaulterSnapshotMethod(ReflectionMethod $method): string
    {
        $params = [];

        foreach ($method->getParameters() as $param) {
            $piece = vaulterSnapshotType($param->getType());
            $piece .= ' ';
            $piece .= $param->isPassedByReference() ? '&' : '';
            $piece .= $param->isVariadic() ? '...' : '';
            $piece .= '$'.$param->getName();

            if ($param->isDefaultValueAvailable()) {
                $piece .= ' = '.($param->isDefaultValueConstant()
                    ? (string) $param->getDefaultValueConstantName()
                    : vaulterSnapshotValue($param->getDefaultValue()));
            }

            $params[] = trim($piece);
        }

        $return = vaulterSnapshotType($method->getReturnType());

        return $method->getName().'('.implode(', ', $params).')'.($return !== '' ? ': '.$return : '');
    }
}

if (!function_exists('vaulterSnapshotInterface')) {
    /**
     * Serialise one contract: FQCN header + its public, non-`@internal` methods (sorted).
     *
     * @param class-string $fqcn
     */
    function vaulterSnapshotInterface(string $fqcn): string
    {
        $reflection = new ReflectionClass($fqcn);
        $lines = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $doc = $method->getDocComment();

            if (($doc !== false && str_contains($doc, '@internal')) || $method->getAttributes(Internal::class) !== []) {
                continue;
            }

            $lines[] = '  '.vaulterSnapshotMethod($method);
        }

        sort($lines);

        return $fqcn."\n".implode("\n", $lines);
    }
}

if (!function_exists('vaulterSnapshotContracts')) {
    /**
     * Discover every `*\Contracts\**` interface under a package src tree and map each
     * file to its FQCN (PSR-4: basename === declared type). `@internal`-marked types are
     * dropped from the frozen set.
     *
     * @return list<class-string> sorted FQCNs
     */
    function vaulterSnapshotContracts(string $contractsDir, string $namespaceRoot): array
    {
        if (!is_dir($contractsDir)) {
            return [];
        }

        /** @var Iterator<SplFileInfo> $files */
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($contractsDir, FilesystemIterator::SKIP_DOTS)),
            '/\.php$/',
        );

        $fqcns = [];

        foreach ($files as $file) {
            $relative = substr(str_replace($contractsDir.'/', '', $file->getPathname()), 0, -4);
            $fqcn = $namespaceRoot.'\\'.str_replace('/', '\\', $relative);

            // Per-branch interface_exists()/class_exists() narrow $fqcn to class-string
            // (an OR of the two does not), so ReflectionClass type-checks without a
            // suppression. Contracts are interfaces by invariant (arch 'contracts are
            // interfaces'); the class branch keeps discovery honest if that ever slips.
            if (interface_exists($fqcn)) {
                $reflection = new ReflectionClass($fqcn);
            } elseif (class_exists($fqcn)) {
                $reflection = new ReflectionClass($fqcn);
            } else {
                continue;
            }

            $doc = $reflection->getDocComment();

            if (($doc !== false && str_contains($doc, '@internal')) || $reflection->getAttributes(Internal::class) !== []) {
                continue;
            }

            $fqcns[] = $fqcn;
        }

        sort($fqcns);

        return array_values(array_unique($fqcns));
    }
}

if (!function_exists('vaulterSnapshotFacade')) {
    /** Freeze the facade by its `@method` docblock (surface over the resolved manager). */
    function vaulterSnapshotFacade(string $facadeFqcn): string
    {
        $doc = (new ReflectionClass($facadeFqcn))->getDocComment() ?: '';
        preg_match_all('/@method\s+(.+)$/m', $doc, $matches);

        $methods = array_map(
            static fn (string $line): string => trim((string) preg_replace('/\s+/', ' ', $line)),
            $matches[1],
        );

        sort($methods);

        return 'FACADE '.$facadeFqcn."\n".implode("\n", array_map(
            static fn (string $m): string => '  '.$m,
            $methods,
        ));
    }
}

if (!function_exists('vaulterSnapshotAssert')) {
    /**
     * Shared gate body: compare the freshly reflected manifest against the committed
     * golden, or regenerate it under the env-gated `UPDATE_SNAPSHOT` escape hatch.
     */
    function vaulterSnapshotAssert(string $manifest, string $goldenPath): void
    {
        if (getenv('UPDATE_SNAPSHOT') === '1') {
            @mkdir(dirname($goldenPath), 0o755, true);
            file_put_contents($goldenPath, $manifest);
            expect(is_file($goldenPath))->toBeTrue();

            return;
        }

        expect(is_file($goldenPath))->toBeTrue();

        expect($manifest)->toBe(
            file_get_contents($goldenPath),
            'Public API surface changed. If this is INTENTIONAL and ADDITIVE, follow AMEND '
            .'(plans/archive/VLT-REL/root/contracts/AMEND.md): regenerate the golden with UPDATE_SNAPSHOT=1, '
            .'add a D#, sync plan.md §7 Contracts, ship a CHANGELOG MINOR. If it is BREAKING '
            .'(removed/renamed method, narrowed type), that is a SemVer decision — escalate to the owner.',
        );
    }
}

test('vaulter-azgard public API surface matches the frozen golden (VLT-REL P2.2)', function (): void {
    $contracts = vaulterSnapshotContracts(__DIR__.'/../../src/Contracts', 'Vaulter\Azgard\Contracts');

    // Discovery must find the enumerated surface — a scan that silently finds nothing
    // would make the whole freeze an inert no-op.
    expect($contracts)->not->toBe([]);

    $blocks = array_map('vaulterSnapshotInterface', $contracts);

    foreach (vaulterSnapshotContracts(__DIR__.'/../../src/Configuration', 'Vaulter\Azgard\Configuration') as $configuration) {
        if (str_ends_with($configuration, 'Config')) {
            $blocks[] = vaulterSnapshotInterface($configuration);
        }
    }

    $blocks[] = vaulterSnapshotInterface(AzgardPermissionDriver::class);

    vaulterSnapshotAssert(implode("\n\n", $blocks)."\n", __DIR__.'/__snapshots__/public-api.txt');
});
