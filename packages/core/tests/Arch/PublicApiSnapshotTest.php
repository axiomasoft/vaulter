<?php

declare(strict_types=1);

use Vaulter\Access\AccessResolver;
use Vaulter\Access\AclFolder;
use Vaulter\Access\ExactListing;
use Vaulter\Access\OperationGuard;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Content\ScanPolicySpec;
use Vaulter\Data\Drive\NodePage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\Permission;
use Vaulter\Enums\Events\EventType;
use Vaulter\Enums\Operations\OperationKind;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Facades\Vaulter;
use Vaulter\Http\Support\HttpResourceResolver;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileProvider;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Profiles\UploadPolicy;
use Vaulter\Schema\HostKeyColumns;
use Vaulter\Schema\VaulterSchema;
use Vaulter\Testing\Contracts\PermissionDriverContractTests;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Access\OwnerRef;
use Vaulter\ValueObjects\Cas\BlobScope;

/**
 * VLT-REL P2.2 — executable freeze of the public composer surface for the `0.1.0`
 * tag. The human-readable SSOT is `plans/archive/VLT-REL/root/contracts/public-api-surface.md`
 * (P2.1); THIS test is its enforceable counterpart: it reflects the LIVE signatures of
 * every `Vaulter\Contracts\**` interface plus the `Vaulter\Facades\Vaulter` facade,
 * canonicalises them into a stable manifest, and compares against a committed golden.
 * Any unplanned change to the surface (a removed/renamed method, a narrowed parameter
 * type, a new contract, a dropped facade `@method`) reddens the build.
 *
 * WHY reflection, not the doc: the doc can drift silently; this test can't — the golden
 * is generated from real signatures, so a `public-api.md:19` breaking change (removal /
 * rename / type narrowing) is caught mechanically, not by review vigilance.
 *
 * Canonicalisation (D5 — signatures only; config/resources/bindings live in the doc):
 *   • Contracts are DISCOVERED by scanning `src/Contracts/**` — so a NEW contract is
 *     frozen automatically (its absence from the golden reddens the build), not just the
 *     ones enumerated today.
 *   • Full signature: parameter name + type (union/intersection/nullable normalised),
 *     by-ref, variadic, default value; return type. Named-argument discipline
 *     (`php:named-arguments`) makes parameter NAMES part of the contract, so they are
 *     frozen too.
 *   • `@internal`-marked interfaces/methods are EXCLUDED (outside the BC promise,
 *     `public-api.md:22`).
 *   • Deterministic order: contracts sorted by FQCN, methods sorted by name, union/
 *     intersection members sorted — so a harmless reorder never reddens the build while
 *     a real signature diff reads cleanly.
 *   • The facade is frozen by its `@method` docblock (it stands over the resolved
 *     `VaulterManager`, Laravel Manager idiom — SSOT §2), not by reflecting the thin
 *     Facade class (whose only real method is the protected accessor).
 *
 * AMEND (adding surface legally after the tag): regenerate the golden with
 * `UPDATE_SNAPSHOT=1`, add a new `D#`, sync `plan.md` §7 Contracts, and ship a CHANGELOG
 * MINOR — see `plans/archive/VLT-REL/root/contracts/AMEND.md`. Regenerating the golden OUTSIDE
 * that procedure (no `D#`) is a `[POSSIBLE-DEFECT]` per `.claude/rules/tests.md`.
 * `UPDATE_SNAPSHOT` is env-gated and is NEVER set by any CI workflow.
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
            $parts = array_map(vaulterSnapshotType(...), $type->getTypes());
            sort($parts);

            return implode('|', $parts);
        }

        if ($type instanceof ReflectionIntersectionType) {
            $parts = array_map(vaulterSnapshotType(...), $type->getTypes());
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
            return '['.implode(', ', array_map(vaulterSnapshotValue(...), $value)).']';
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

            if ($doc !== false && str_contains($doc, '@internal')) {
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

            if ($doc !== false && str_contains($doc, '@internal')) {
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
        $doc = new ReflectionClass($facadeFqcn)->getDocComment() ?: '';
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

test('vaulter-core public API surface matches the frozen golden (VLT-REL P2.2)', function (): void {
    $contracts = vaulterSnapshotContracts(__DIR__.'/../../src/Contracts', 'Vaulter\Contracts');

    // Discovery must find the enumerated surface — a scan that silently finds nothing
    // would make the whole freeze an inert no-op.
    expect($contracts)->not->toBe([]);

    $blocks = array_map(vaulterSnapshotInterface(...), $contracts);

    foreach (vaulterSnapshotContracts(__DIR__.'/../../src/Configuration', 'Vaulter\Configuration') as $configuration) {
        $blocks[] = vaulterSnapshotInterface($configuration);
    }
    foreach ([AccessResolver::class, AclFolder::class, ExactListing::class, NodePage::class] as $class) {
        $blocks[] = vaulterSnapshotInterface($class);
    }

    foreach ([AccessScope::class, OwnerRef::class, Actor::class, ActorRef::class, OperationContext::class, NodeLocator::class, OperationGuard::class, MissingActorException::class, ProfileRestrictionException::class, Profile::class, ProfileRegistry::class, ProfileProvider::class, UploadPolicy::class, ScanPolicySpec::class] as $class) {
        $reflection = new ReflectionClass($class);
        $properties = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $properties[] = '  '.($property->isReadOnly() ? 'readonly ' : '').vaulterSnapshotType($property->getType()).' $'.$property->getName();
        }
        sort($properties);
        $blocks[] = vaulterSnapshotInterface($class).($properties === [] ? '' : "\n".implode("\n", $properties));
    }
    $blocks[] = OperationKind::class."\n".implode("\n", array_map(
        static fn (OperationKind $case): string => '  '.$case->name.' = '.$case->value,
        OperationKind::cases(),
    ));
    $blocks[] = vaulterSnapshotInterface(PermissionDriverContractTests::class);
    foreach ([Permission::class, GrantLevel::class, Ability::class] as $enum) {
        $blocks[] = $enum."\n".implode("\n", array_map(static fn ($case): string => '  '.$case->name.' = '.$case->value, $enum::cases()));
    }
    $blocks[] = ActorRef::class.'::SYSTEM_TYPE = '.ActorRef::SYSTEM_TYPE;

    $blocks[] = vaulterSnapshotInterface(VaulterDatabase::class);
    $blocks[] = vaulterSnapshotInterface(BlobScope::class);
    $blocks[] = vaulterSnapshotInterface(HostKeyColumns::class);
    $blocks[] = vaulterSnapshotInterface(VaulterSchema::class);
    $blocks[] = vaulterSnapshotInterface(HttpResourceResolver::class);
    $blocks[] = vaulterSnapshotInterface(ProblemException::class);
    foreach ([NodeNotFoundException::class => 'forId', StorageNotFoundException::class => 'forId', VaulterException::class => 'render'] as $class => $method) {
        $blocks[] = $class."\n  ".vaulterSnapshotMethod(new ReflectionMethod($class, $method));
    }

    $blocks[] = vaulterSnapshotInterface(UpgradePreflightException::class);
    $blocks[] = EventType::class."\n".implode("\n", array_map(
        static fn (EventType $case): string => '  '.$case->name.' = '.$case->value,
        EventType::cases(),
    ));
    $blocks[] = vaulterSnapshotFacade(Vaulter::class);

    foreach (vaulterSnapshotContracts(__DIR__.'/../../src/Events', 'Vaulter\Events') as $eventClass) {
        $reflection = new ReflectionClass($eventClass);
        $properties = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $properties[] = '  '.($property->isReadOnly() ? 'readonly ' : '')
                .vaulterSnapshotType($property->getType()).' $'.$property->getName();
        }

        sort($properties);
        $blocks[] = vaulterSnapshotInterface($eventClass)."\n".implode("\n", $properties);
    }

    vaulterSnapshotAssert(implode("\n\n", $blocks)."\n", __DIR__.'/__snapshots__/public-api.txt');
});
