<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Route;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Http\Middleware\EnsureModuleActive;
use Vaulter\Http\Problems\ProblemCatalog;
use Vaulter\Kernel;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleCatalog;
use Vaulter\Modules\ModuleState;
use Vaulter\Modules\VaulterModule;
use Vaulter\Tests\Concerns\FreshApplication;
use Vaulter\Tests\Concerns\WithoutDatabaseSchema;
use Vaulter\Tests\TestCase;

uses(TestCase::class, WithoutDatabaseSchema::class);

// I5 (knowledge/p3-surface#problems, D39): every identity of the sealed registry has exactly one owning module, and it
// is the owner the identity inventory (tests/Repo/Arch/Fixtures/identities) names. A slice still located in core is
// declared by the manifest of its owner. What is declared by another module until a later phase moves it is listed
// below exactly, with that phase — an expectation, not a skip: a new mismatch or a resolved one fails the test.

/**
 * Identities declared by another module than their owner until the named phase moves them.
 *
 * @var array<string, array{declared_by: string, phase: string, ids: list<string>}>
 */
const I5_DECLARED_ELSEWHERE = [
    'collaboration' => [
        'declared_by' => 'documents',
        'phase' => 'P5',
        'ids' => [
            'problem:collaboration_epoch_mismatch',
            'problem:collaboration_seed_conflict',
            'problem:collaboration_unavailable',
            'route:vaulter.v1.documents.collaboration.session',
        ],
    ],
];

/**
 * Config roots holding keys of other owners until the config split of the named phase (D36, P3.6).
 *
 * @var array<string, array{declared_by: string, phase: string, owners: list<string>}>
 */
const I5_SHARED_CONFIG_ROOTS = [
    'vaulter' => ['declared_by' => 'core', 'phase' => 'P4', 'owners' => ['core', 'drive', 'media', 'webhooks']],
    'vaulter-documents' => ['declared_by' => 'documents', 'phase' => 'P5', 'owners' => ['collaboration', 'documents']],
];

/**
 * Routes of the inventory a default installation does not mount even with every module active: the collaboration
 * hub needs a configured hub (P5).
 */
const I5_UNMOUNTED_ROUTES = [
    'route:vaulter.v1.collaboration.hub.authenticate',
    'route:vaulter.v1.collaboration.hub.load',
    'route:vaulter.v1.collaboration.hub.store',
];

/**
 * Tables the migrations of the core module create for other owners until P4 splits the create-migrations into the
 * schema descriptors of the owners (D36).
 *
 * @var array<string, list<string>>
 */
const I5_TABLES_BY_OWNER_UNTIL_P4 = [
    'documents' => ['v_document_revisions', 'v_documents', 'v_node_comments'],
    'drive' => [
        'v_activity', 'v_drive_change_heads', 'v_drive_changes', 'v_drives', 'v_file_revision_blobs', 'v_file_revisions', 'v_files',
        'v_node_grants', 'v_node_shares', 'v_node_user_states', 'v_nodes', 'v_scan_backfill_runs', 'v_scan_evidence',
        'v_scan_obligations', 'v_scan_policy_states', 'v_scan_requests', 'v_upload_parts', 'v_upload_sessions',
    ],
    'media' => ['v_blobs'],
    'webhooks' => ['v_webhook_deliveries', 'v_webhook_endpoints'],
];

/**
 * Modules whose packages the monorepo root does not install (azgard needs AzGuard, filament needs Filament): their
 * identities are proven by their own suites, and this test pins how many there are.
 *
 * @var array<string, array<string, int>>
 */
const I5_OUTSIDE_THIS_SUITE = ['azgard' => ['config' => 15], 'filament' => ['config' => 6]];

function i5App(): Application
{
    static $app = null;

    return $app ??= FreshApplication::boot(['vaulter.modules' => ['webhooks' => true]], after: [VaulterDocumentsServiceProvider::class]);
}

/**
 * The declarations of every offered manifest, by module id.
 *
 * @return array<string, ModuleBuilder>
 */
function i5Builders(): array
{
    $builders = [];
    foreach (ModuleCatalog::of(i5App())->offered() as $manifest) {
        if (!is_a($manifest, VaulterModule::class, true)) {
            continue;
        }
        $module = new $manifest;
        $builder = new ModuleBuilder($module->id());
        $module->register($builder);
        $builders[$module->id()->value] = $builder;
    }
    ksort($builders);

    return $builders;
}

/**
 * The items of an identity fixture, by id.
 *
 * @return array<string, array<string, mixed>>
 */
function i5Identities(string $kind): array
{
    $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/tests/Repo/Arch/Fixtures/identities/'.$kind.'.json'), true, flags: JSON_THROW_ON_ERROR);

    return array_column($fixture['items'], null, 'id');
}

/** The module expected to declare `$id` of the owner `$owner`. */
function i5ExpectedDeclarer(string $id, string $owner): string
{
    foreach (I5_DECLARED_ELSEWHERE as $realOwner => $entry) {
        if ($realOwner === $owner && in_array($id, $entry['ids'], true)) {
            return $entry['declared_by'];
        }
    }

    return $owner;
}

it('compiles the full set with every module active', function (): void {
    $kernel = i5App()->make(Kernel::class);

    expect($kernel->isValid())->toBeTrue(implode('; ', $kernel->reasons()))
        ->and(array_values(array_filter($kernel->modules(), static fn (ModuleState $s): bool => !$s->active)))->toBe([])
        ->and(array_keys(i5Builders()))->toBe(['core', 'documents', 'drive', 'media', 'webhooks']);
});

it('gives every problem code the owner of the inventory', function (): void {
    $catalog = i5App()->make(ProblemCatalog::class)->all();
    $declared = [];
    foreach (i5Identities('protocol') as $id => $item) {
        if (!str_starts_with($id, 'problem:')) {
            continue;
        }
        $code = substr($id, strlen('problem:'));
        expect($catalog)->toHaveKey($code);
        $declared[$id] = $catalog[$code]['module'];
        expect($declared[$id])->toBe(i5ExpectedDeclarer($id, $item['owner']), $id);
    }

    expect(count($declared))->toBe(count($catalog));
});

it('gives every command the owner of the inventory', function (): void {
    $byClass = [];
    foreach (i5Builders() as $module => $builder) {
        foreach ($builder->commandClasses() as $class) {
            expect($byClass)->not->toHaveKey($class);
            $byClass[$class] = $module;
        }
    }
    $registered = array_map(static fn (object $command): string => $command::class, i5App()->make(ConsoleKernel::class)->all());

    foreach (i5Identities('cli') as $id => $item) {
        expect($byClass)->toHaveKey($item['class'], $id)
            ->and($byClass[$item['class']])->toBe(i5ExpectedDeclarer($id, $item['owner']), $id)
            ->and($registered)->toContain($item['class']);
    }
    expect(count($byClass))->toBe(count(i5Identities('cli')));
});

it('mounts every route behind the middleware of the owner of the inventory', function (): void {
    $mounted = [];
    foreach (i5App()['router']->getRoutes()->getRoutes() as $route) {
        /** @var Route $route */
        $modules = array_values(array_filter(array_map(
            static fn (mixed $m): ?string => is_string($m) && str_starts_with($m, EnsureModuleActive::ALIAS.':') ? substr($m, strlen(EnsureModuleActive::ALIAS) + 1) : null,
            $route->middleware(),
        )));
        $name = (string) $route->getName();
        if (!str_starts_with($name, 'vaulter.')) {
            continue;
        }
        expect($modules)->toHaveCount(1, $name);
        $mounted['route:'.$name] = $modules[0];
    }

    $unmounted = [];
    foreach (i5Identities('http') as $id => $item) {
        if (!isset($mounted[$id])) {
            $unmounted[] = $id;

            continue;
        }
        expect($mounted[$id])->toBe(i5ExpectedDeclarer($id, $item['owner']), $id);
    }
    sort($unmounted);

    expect($unmounted)->toBe(I5_UNMOUNTED_ROUTES)
        ->and(array_diff(array_keys($mounted), array_keys(i5Identities('http'))))->toBe([]);
});

it('declares every config root once, by the owner of its keys', function (): void {
    $owners = [];
    foreach (i5Identities('config') as $id => $item) {
        if (str_starts_with($id, 'config:')) {
            $owners[explode('.', substr($id, strlen('config:')))[0]][$item['owner']] = true;
        }
    }
    $declared = [];
    foreach (i5Builders() as $module => $builder) {
        foreach (array_keys($builder->configs()) as $root) {
            expect($declared)->not->toHaveKey($root);
            $declared[$root] = $module;
        }
    }

    foreach ($declared as $root => $module) {
        $keyOwners = array_keys($owners[$root] ?? []);
        sort($keyOwners);
        if (isset(I5_SHARED_CONFIG_ROOTS[$root])) {
            expect($module)->toBe(I5_SHARED_CONFIG_ROOTS[$root]['declared_by'])
                ->and($keyOwners)->toBe(I5_SHARED_CONFIG_ROOTS[$root]['owners'], $root);

            continue;
        }
        expect($keyOwners)->toBe([$module], $root);
    }

    // The roots of the modules this suite does not install are the only undeclared ones.
    $undeclared = array_values(array_diff(array_keys($owners), array_keys($declared)));
    sort($undeclared);
    expect($undeclared)->toBe(['vaulter-azgard', 'vaulter-filament']);
    foreach (I5_OUTSIDE_THIS_SUITE as $module => $counts) {
        $keys = array_filter(i5Identities('config'), static fn (array $item, string $id): bool => str_starts_with($id, 'config:') && $item['owner'] === $module, ARRAY_FILTER_USE_BOTH);
        expect(count($keys))->toBe($counts['config'], $module);
    }
});

it('defines every slot and capability in the module that owns its contract', function (): void {
    $map = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/tests/Repo/Arch/Fixtures/target-map.json'), true, flags: JSON_THROW_ON_ERROR);
    $owners = array_column($map['types'], 'owner', 'fqcn');
    $compiled = i5App()->make(Kernel::class)->compiled();

    $definitions = [
        ...array_map(static fn ($d): string => $d->module, $compiled->capabilities),
        ...array_map(static fn ($d): string => $d->module, $compiled->slots),
    ];
    expect($definitions)->not->toBeEmpty();
    foreach ($definitions as $contract => $module) {
        expect($owners)->toHaveKey($contract)
            ->and($owners[$contract])->toBe($module, $contract);
    }
});

it('expects the event types as module contributions from P3.13', function (): void {
    // EventType is still one kernel enum with the values of every module (D38): no module declares events yet, so
    // the owners of the inventory cannot be compared with a registry. P3.13 turns this expectation around.
    $events = array_filter(i5Identities('protocol'), static fn (string $id): bool => str_starts_with($id, 'event:'), ARRAY_FILTER_USE_KEY);
    $owners = array_values(array_unique(array_column($events, 'owner')));
    sort($owners);

    expect(i5App()->make(Kernel::class)->compiled()->events)->toBe([])
        ->and($events)->not->toBeEmpty()
        ->and($owners)->toBe(['documents', 'drive']);
});

it('installs every table from the core migrations until P4 splits them by owner', function (): void {
    $schemaModules = array_keys(array_filter(i5Builders(), static fn (ModuleBuilder $b): bool => $b->schemaPaths() !== []));
    $byOwner = [];
    foreach (i5Identities('schema') as $id => $item) {
        if (str_starts_with($id, 'table:') && $item['owner'] !== 'core') {
            expect($item['source']['file'])->toStartWith('packages/core/database/migrations/');
            $byOwner[$item['owner']][] = substr($id, strlen('table:'));
        }
    }
    ksort($byOwner);
    $byOwner = array_map(static function (array $tables): array {
        sort($tables);

        return $tables;
    }, $byOwner);

    expect($schemaModules)->toBe(['core'])
        ->and($byOwner)->toBe(I5_TABLES_BY_OWNER_UNTIL_P4);
});
