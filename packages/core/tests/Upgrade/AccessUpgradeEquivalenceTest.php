<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\ExecutableFinder;
use Vaulter\Access\AccessResolver;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Upgrade\LoadsV02Fixture;

uses(LoadsV02Fixture::class);

/**
 * 2026_10_07_000005_access_model_v1 never widens access silently (P4-I21, PLAN2.D45,
 * D46, D53). The (actor × node × ability) matrix computed by the 0.x algorithm over
 * the legacy rows is compared with the 1.0 resolver after the migration. The only
 * differences allowed are the declared role changes of D53:
 *   writer     gains trash and restore;
 *   organizer  gains share and loses permanent delete.
 * v_nodes.owner_id granted nothing under the built-in drivers, so it converts to
 * nothing. Deny grants and extra permission bits stop the migration unchanged.
 *
 * The legacy shape is rebuilt with the migration's own down() over a migrated
 * database, so the test runs on every driver; on PostgreSQL the 0.2 fixture rows
 * take part as well.
 */
afterEach(function (): void {
    $db = app(VaulterDatabase::class)->connection();
    if ($db->transactionLevel() === 0 && ($db->getDriverName() === 'sqlite' || str_ends_with($db->getDatabaseName(), '_test'))) {
        $db->getSchemaBuilder()->dropAllTables();
    }
    RefreshDatabaseState::$migrated = false;
});

const ACCESS_V1_MIGRATION = '2026_10_07_000005_access_model_v1';

/** The 0.x level bits and the 0.x ability → required bits (READ=1 UPDATE=2 CREATE=4 DELETE=8 SHARE=16 COMMENT=32). */
const LEGACY_LEVEL_BITS = ['reader' => 1, 'commenter' => 33, 'writer' => 39, 'organizer' => 47, 'owner' => 63];

const LEGACY_REQUIRED = [
    'view' => 1, 'download' => 1, 'comment' => 32, 'edit' => 2, 'create' => 4, 'move' => 6, 'copy' => 4,
    'trash' => 8, 'restore' => 8, 'delete' => 8, 'share' => 16, 'manage' => 63,
];

function accessV1Migration(): object
{
    return require __DIR__.'/../../database/migrations/2026_10_07_000005_access_model_v1.php';
}

/** Back to the 0.3 grants shape with the migration's own down(). */
function accessV1Before(Connection $db): void
{
    $db->table('migrations')->where('migration', ACCESS_V1_MIGRATION)->update(['batch' => 99]);
    accessV1Migration()->down();
    $db->table('migrations')->where('migration', ACCESS_V1_MIGRATION)->delete();
}

function accessV1Prepare(object $test): Connection
{
    $test->artisan('migrate', ['--force' => true])->assertSuccessful();
    $db = app(VaulterDatabase::class)->connection();
    accessV1Before($db);

    return $db;
}

/** @param array<string, mixed> $extra */
function accessV1Node(Connection $db, int|string $driveId, int|string|null $parentId, string $name, array $extra = []): int|string
{
    return $db->table(app(DatabaseConfig::class)->table('nodes'))->insertGetId([
        'drive_id' => $driveId, 'parent_id' => $parentId, 'kind' => 'folder', 'name' => $name, 'name_key' => mb_strtolower($name),
        'inherit_grants' => true, 'created_at' => now(), 'updated_at' => now(), ...$extra,
    ]);
}

/** @param array<string, mixed> $extra */
function accessV1Grant(Connection $db, int|string $nodeId, int $userId, string $level, string $scope = 'subtree', array $extra = []): int|string
{
    return $db->table(app(DatabaseConfig::class)->table('node_grants'))->insertGetId([
        'node_id' => $nodeId, 'subject_type' => AccessTestHelpers::user($userId)->getMorphClass(), 'subject_id' => $userId,
        'level' => $level, 'permissions' => 0, 'effect' => 'allow', 'scope' => $scope,
        'created_at' => now(), 'updated_at' => now(), ...$extra,
    ]);
}

/**
 * A tree exercising every legacy clause: subtree and item grants, a boundary, an
 * expired grant, permissions inside the level bits, owner_id on a node, and every
 * level on its own and combined.
 *
 * @return list<int> the actor ids to evaluate
 */
function accessV1Seed(Connection $db): array
{
    $drives = app(DatabaseConfig::class)->table('drives');
    $drive = $db->table($drives)->insertGetId(['owner_type' => 'team', 'owner_id' => 900, 'name' => 'Access', 'profile' => 'default', 'created_at' => now(), 'updated_at' => now()]);
    $root = accessV1Node($db, $drive, null, 'root');
    $db->table($drives)->where('id', $drive)->update(['root_node_id' => $root]);
    $proj = accessV1Node($db, $drive, $root, 'proj');
    $hr = accessV1Node($db, $drive, $proj, 'hr', ['inherit_grants' => false]);
    $payroll = accessV1Node($db, $drive, $hr, 'payroll');
    $docs = accessV1Node($db, $drive, $proj, 'docs', ['owner_id' => 905]);
    $deep = accessV1Node($db, $drive, $docs, 'deep');

    accessV1Grant($db, $proj, 901, 'writer');
    accessV1Grant($db, $hr, 902, 'reader');
    accessV1Grant($db, $proj, 903, 'commenter', 'item');
    accessV1Grant($db, $docs, 903, 'organizer');
    accessV1Grant($db, $root, 904, 'owner');
    accessV1Grant($db, $docs, 906, 'reader', 'subtree', ['permissions' => 1]);
    accessV1Grant($db, $deep, 906, 'writer', 'item', ['permissions' => 6]);
    accessV1Grant($db, $proj, 907, 'owner', 'subtree', ['expires_at' => Carbon::now()->subHour()]);
    accessV1Grant($db, $payroll, 907, 'organizer', 'item', ['expires_at' => Carbon::now()->addDay()]);
    accessV1Grant($db, $hr, 908, 'commenter');
    accessV1Grant($db, $payroll, 908, 'writer', 'item');

    // 905 owns /proj/docs through owner_id only; 909 has nothing at all.
    return [901, 902, 903, 904, 905, 906, 907, 908, 909];
}

/**
 * The 0.x allow-only algorithm over the raw rows: the node's grants of any scope,
 * the subtree grants of its ancestors, walking up within the drive and stopping
 * after the first inherit_grants=false node; bits = level bits | permissions.
 *
 * @return array<string, bool> "actor:node:ability" => allowed
 */
function accessV1LegacyMatrix(Connection $db, array $actors): array
{
    $nodes = $db->table(app(DatabaseConfig::class)->table('nodes'))->get()->keyBy(fn ($row): string => (string) $row->id);
    $grants = $db->table(app(DatabaseConfig::class)->table('node_grants'))->get();
    $now = Carbon::now();
    $matrix = [];
    foreach ($actors as $actor) {
        $type = AccessTestHelpers::user($actor)->getMorphClass();
        foreach ($nodes as $id => $node) {
            $bits = 0;
            $current = $node;
            $depth = 0;
            while ($current !== null && (string) $current->drive_id === (string) $node->drive_id) {
                foreach ($grants as $grant) {
                    if ((string) $grant->node_id !== (string) $current->id || $grant->subject_type !== $type || (string) $grant->subject_id !== (string) $actor) {
                        continue;
                    }
                    if ($grant->expires_at !== null && Carbon::parse($grant->expires_at)->lessThanOrEqualTo($now)) {
                        continue;
                    }
                    if ($depth > 0 && $grant->scope !== 'subtree') {
                        continue;
                    }
                    $bits |= LEGACY_LEVEL_BITS[$grant->level] | (int) $grant->permissions;
                }
                if (!$current->inherit_grants) {
                    break;
                }
                $current = $current->parent_id === null ? null : $nodes[(string) $current->parent_id] ?? null;
                $depth++;
            }
            foreach (LEGACY_REQUIRED as $ability => $required) {
                $matrix[$actor.':'.$id.':'.$ability] = ($bits & $required) === $required;
            }
        }
    }

    return $matrix;
}

/** @return array<string, bool> */
function accessV1NewMatrix(Connection $db, array $actors): array
{
    config()->set('vaulter.access.permission_driver', 'database');
    $resolver = app(AccessResolver::class);
    $matrix = [];
    foreach ($actors as $actor) {
        $user = AccessTestHelpers::user($actor);
        foreach (Node::query()->get() as $node) {
            foreach (Ability::cases() as $ability) {
                $matrix[$actor.':'.$node->id.':'.$ability->value] = $resolver->can($user, $ability, $node);
            }
        }
    }

    return $matrix;
}

/**
 * The 1.0 verdict a cell must have: the legacy verdict, except for the D53 role
 * changes of the levels that applied there.
 *
 * @param list<string> $levels
 */
function accessV1Declared(string $ability, array $levels, bool $legacyAllowed): bool
{
    $has = static fn (string $level): bool => in_array($level, $levels, true);

    return match ($ability) {
        'trash', 'restore' => $legacyAllowed || $has('writer'),
        'share' => $legacyAllowed || $has('organizer'),
        'delete' => $has('owner'),
        default => $legacyAllowed,
    };
}

/** @return array<string, list<string>> "actor:node" => the live legacy levels that apply */
function accessV1AppliedLevels(Connection $db, array $actors): array
{
    $nodes = $db->table(app(DatabaseConfig::class)->table('nodes'))->get()->keyBy(fn ($row): string => (string) $row->id);
    $grants = $db->table(app(DatabaseConfig::class)->table('node_grants'))->get();
    $now = Carbon::now();
    $applied = [];
    foreach ($actors as $actor) {
        $type = AccessTestHelpers::user($actor)->getMorphClass();
        foreach ($nodes as $id => $node) {
            $levels = [];
            $current = $node;
            $depth = 0;
            while ($current !== null && (string) $current->drive_id === (string) $node->drive_id) {
                foreach ($grants as $grant) {
                    if ((string) $grant->node_id === (string) $current->id && $grant->subject_type === $type && (string) $grant->subject_id === (string) $actor
                        && ($grant->expires_at === null || Carbon::parse($grant->expires_at)->greaterThan($now))
                        && ($depth === 0 || $grant->scope === 'subtree')) {
                        $levels[] = $grant->level;
                    }
                }
                if (!$current->inherit_grants) {
                    break;
                }
                $current = $current->parent_id === null ? null : $nodes[(string) $current->parent_id] ?? null;
                $depth++;
            }
            $applied[$actor.':'.$id] = $levels;
        }
    }

    return $applied;
}

it('keeps the access matrix of every convertible grant, up to the declared role changes', function (bool $fixture): void {
    if ($fixture && (app(VaulterDatabase::class)->driver() !== 'pgsql' || (new ExecutableFinder)->find('psql') === null)) {
        // The 0.2 fixture exists for PostgreSQL only; the synthetic tree runs everywhere.
        $this->markTestSkipped('The 0.2 fixture requires PostgreSQL and psql.');
    }
    if ($fixture) {
        $this->loadV02Fixture('bigint');
    }
    $db = accessV1Prepare($this);
    $actors = accessV1Seed($db);
    if ($fixture) {
        $actors = [...$actors, 101, 102];
    }
    $legacy = accessV1LegacyMatrix($db, $actors);
    $levels = accessV1AppliedLevels($db, $actors);
    $grantCount = $db->table(app(DatabaseConfig::class)->table('node_grants'))->count();

    accessV1Migration()->up();

    $schema = $db->getSchemaBuilder();
    $grants = app(DatabaseConfig::class)->table('node_grants');
    expect($schema->hasColumns($grants, ['role_key', 'role_version']))->toBeTrue()
        ->and($schema->hasColumn($grants, 'level'))->toBeFalse()
        ->and($schema->hasColumn($grants, 'effect'))->toBeFalse()
        ->and($schema->hasColumn($grants, 'permissions'))->toBeFalse()
        ->and($schema->hasColumn(app(DatabaseConfig::class)->table('nodes'), 'owner_id'))->toBeFalse()
        ->and($db->table($grants)->count())->toBe($grantCount)
        ->and($db->table($grants)->where('role_version', '!=', 1)->count())->toBe(0);

    $new = accessV1NewMatrix($db, $actors);
    expect(array_keys($new))->toEqualCanonicalizing(array_keys($legacy));
    $mismatches = [];
    foreach ($legacy as $cell => $was) {
        [$actor, $node, $ability] = explode(':', $cell);
        $expected = accessV1Declared($ability, $levels[$actor.':'.$node], $was);
        if ($new[$cell] !== $expected) {
            $mismatches[] = $cell.' legacy='.var_export($was, true).' new='.var_export($new[$cell], true);
        }
        // A cell nobody could reach before is never reachable after (P4-I21).
        if (!$was && $new[$cell] && $levels[$actor.':'.$node] === []) {
            $mismatches[] = $cell.' widened without any grant';
        }
    }
    expect($mismatches)->toBe([]);

    // Spot checks of the declared changes on the seed.
    $docs = (string) $db->table(app(DatabaseConfig::class)->table('nodes'))->where('name', 'docs')->value('id');
    $proj = (string) $db->table(app(DatabaseConfig::class)->table('nodes'))->where('name', 'proj')->value('id');
    expect($legacy["905:{$docs}:view"])->toBeFalse()->and($new["905:{$docs}:view"])->toBeFalse()
        ->and($legacy["901:{$proj}:trash"])->toBeFalse()->and($new["901:{$proj}:trash"])->toBeTrue()
        ->and($legacy["903:{$docs}:delete"])->toBeTrue()->and($new["903:{$docs}:delete"])->toBeFalse()
        ->and($new["903:{$docs}:share"])->toBeTrue();

    // A second run finds the 1.0 shape and changes nothing.
    accessV1Migration()->up();
    expect($db->table($grants)->count())->toBe($grantCount);
})->with(['synthetic tree' => false, '0.2 fixture (PostgreSQL)' => true]);

it('refuses deny grants, unknown levels and extra permission bits and changes nothing', function (array $row, string $needle): void {
    $db = accessV1Prepare($this);
    accessV1Seed($db);
    $proj = $db->table(app(DatabaseConfig::class)->table('nodes'))->where('name', 'proj')->value('id');
    $id = accessV1Grant($db, $proj, 950, 'reader', 'item', $row);

    expect(fn () => accessV1Migration()->up())->toThrow(UpgradePreflightException::class, $needle);

    $grants = app(DatabaseConfig::class)->table('node_grants');
    expect($db->getSchemaBuilder()->hasColumn($grants, 'role_key'))->toBeFalse()
        ->and($db->getSchemaBuilder()->hasColumn(app(DatabaseConfig::class)->table('nodes'), 'owner_id'))->toBeTrue()
        ->and($db->table($grants)->where('id', $id)->exists())->toBeTrue();
})->with([
    'deny' => [['effect' => 'deny'], 'is a deny grant'],
    'unknown level' => [['level' => 'editor'], 'unknown level editor'],
    'extra bits' => [['permissions' => 16], 'adds permission bits 16 to reader'],
]);

it('converts permissions inside the level bits and rolls back to the 0.3 shape', function (): void {
    $db = accessV1Prepare($this);
    accessV1Seed($db);
    $grants = app(DatabaseConfig::class)->table('node_grants');

    accessV1Migration()->up();
    expect($db->table($grants)->where('subject_id', 906)->orderBy('id')->pluck('role_key')->all())->toBe(['reader', 'writer']);

    $db->table('migrations')->insert(['migration' => ACCESS_V1_MIGRATION, 'batch' => 99]);
    accessV1Before($db);
    $schema = $db->getSchemaBuilder();
    expect($schema->hasColumns($grants, ['level', 'permissions', 'effect']))->toBeTrue()
        ->and($schema->hasColumn($grants, 'role_key'))->toBeFalse()
        ->and($schema->hasColumn(app(DatabaseConfig::class)->table('nodes'), 'owner_id'))->toBeTrue()
        ->and($db->table($grants)->where('subject_id', 906)->orderBy('id')->pluck('level')->all())->toBe(['reader', 'writer'])
        ->and($db->table($grants)->distinct()->pluck('effect')->all())->toBe(['allow']);

    accessV1Migration()->up();
    expect($schema->hasColumn($grants, 'role_key'))->toBeTrue();
});
