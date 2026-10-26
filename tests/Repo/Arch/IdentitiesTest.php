<?php

declare(strict_types=1);

use PhpParser\ParserFactory;
use Vaulter\Inventory\Identities;
use Vaulter\Inventory\Identities\Config;
use Vaulter\Inventory\Identities\Http;
use Vaulter\Inventory\Identities\Protocol;
use Vaulter\Inventory\Identities\Schema;
use Vaulter\Inventory\IdentityOwners;
use Vaulter\Inventory\InventoryError;
use Vaulter\Inventory\Json;
use Vaulter\Inventory\Repo;
use Vaulter\Inventory\SourceIndex;
use Vaulter\Inventory\TargetMap;

/*
 * The identities inventory: which tables, config keys, commands, routes and protocol names exist and who owns each.
 * The unit tests run the extractors on synthetic input (strings and temp directories, never .php files: Pint would
 * reformat them); the last tests check the five committed tests/Repo/Arch/Fixtures/identities/<kind>.json against
 * the owners files and the real code.
 * Fix a red fixture with: scripts/php84 php scripts/inventory/inventory.php identities-<kind> --write
 */

require_once dirname(__DIR__, 3).'/scripts/inventory/bootstrap.php';
require_once dirname(__DIR__, 3).'/scripts/inventory/lib/Identities/Schema.php';
require_once dirname(__DIR__, 3).'/scripts/inventory/lib/Identities/Config.php';
require_once dirname(__DIR__, 3).'/scripts/inventory/lib/Identities/Protocol.php';
require_once dirname(__DIR__, 3).'/scripts/inventory/lib/Identities/Http.php';

/** The decisions a ledger record may cite: accepted decisions only; a plan item adds one together with its record. */
const ID_ACCEPTED_DECISIONS = ['PLAN4-D16', 'PLAN4-D17', 'PLAN4-D4', 'D1', 'D6', 'D8', 'D10', 'D14', 'D21', 'D22', 'D23', 'D25', 'D26', 'D28', 'D29', 'D33', 'D34', 'D36', 'D38', 'D39', 'D40', 'D46', 'D47', 'D49'];

/** @return list<string> the problem codes, sorted */
function id_codes(array $problems): array
{
    $codes = array_column($problems, 'code');
    sort($codes);

    return $codes;
}

/** @return array<string, mixed> */
function id_fixture(string $kind): array
{
    return Repo::discover()->readJson(Identities::fixture($kind));
}

/** A temp repository holding the snapshots of a one-table schema for every preset and engine. */
function id_schema_repo(Closure $column): Repo
{
    $root = sys_get_temp_dir().'/vaulter-identities-'.bin2hex(random_bytes(6));
    $repo = Repo::at($root);
    $axes = Repo::discover()->readJson(Schema::FIXTURES.'/snapshot-configs.json');
    $repo->write(Schema::FIXTURES.'/snapshot-configs.json', Json::encode($axes));
    $repo->write('packages/core/database/migrations/2026_10_13_000001_create_v_things_table.php', "<?php\n");
    $repo->write(Identities::ownersFile('schema'), Json::encode(['kind' => 'schema', 'rules' => [['match' => 'exact', 'value' => 'table:v_things', 'owner' => 'drive', 'why' => 'test']]]));

    $names = [];
    foreach (['bigint', 'ulid', 'uuid7'] as $ids) {
        foreach (['string', 'bigint', 'uuid', 'ulid'] as $hostKeys) {
            foreach (['v', 'custom'] as $prefix) {
                foreach (['off', 'on'] as $tenancy) {
                    $names[] = "{$ids}.{$hostKeys}.{$prefix}.{$tenancy}";
                }
            }
        }
    }
    foreach (['expected', 'original'] as $kind) {
        foreach (Schema::ENGINES as $engine) {
            foreach ($names as $preset) {
                [$ids, $hostKeys, $prefix, $tenancy] = explode('.', $preset);
                $table = $prefix === 'v' ? 'v_things' : 'custom_things';
                $columns = [['name' => 'id', 'type' => 'bigint', 'not_null' => true, 'default' => null], $column($ids, $hostKeys, $prefix, $tenancy, $kind, $engine)];
                $repo->write(Schema::FIXTURES."/{$kind}/{$engine}/{$preset}.json", Json::encode(['tables' => [$table => ['columns' => $columns, 'indexes' => [], 'constraints' => []]]]));
            }
        }
    }

    return $repo;
}

function id_remove(string $dir): void
{
    foreach (glob($dir.'/*') ?: [] as $path) {
        is_dir($path) ? id_remove($path) : unlink($path);
    }
    @rmdir($dir);
}

describe('owner rules', function (): void {
    it('prefers exact over the longest prefix over glob and marks only winners as used', function (): void {
        $owners = IdentityOwners::fromArray('x', ['rules' => [
            ['match' => 'glob', 'value' => 'a:*', 'owner' => 'core', 'why' => 'w'],
            ['match' => 'prefix', 'value' => 'a:b', 'owner' => 'media', 'why' => 'w'],
            ['match' => 'prefix', 'value' => 'a:bc', 'owner' => 'drive', 'why' => 'w'],
            ['match' => 'exact', 'value' => 'a:bcd', 'owner' => 'documents', 'why' => 'w'],
        ]]);

        expect($owners->resolve('a:bcd'))->toBe('documents')
            ->and($owners->resolve('a:bce'))->toBe('drive')
            ->and($owners->resolve('a:bx'))->toBe('media')
            ->and($owners->resolve('a:zz'))->toBe('core')
            ->and($owners->resolve('b:zz'))->toBeNull()
            ->and($owners->stale())->toBe([]);
    });

    it('reports a rule that never wins as stale and a malformed rule as invalid', function (): void {
        $owners = IdentityOwners::fromArray('x', ['rules' => [
            ['match' => 'prefix', 'value' => 'a:', 'owner' => 'drive', 'why' => 'w'],
            ['match' => 'exact', 'value' => 'a:gone', 'owner' => 'drive', 'why' => 'w'],
            ['match' => 'exact', 'value' => 'a:x', 'owner' => 'nobody', 'why' => 'w'],
            ['match' => 'regex', 'value' => 'a', 'owner' => 'drive', 'why' => 'w'],
            ['match' => 'exact', 'value' => 'a:y', 'owner' => 'drive', 'why' => ''],
            ['match' => 'prefix', 'value' => 'a:', 'owner' => 'core', 'why' => 'w'],
        ]]);
        $owners->resolve('a:thing');

        expect(id_codes($owners->problems()))->toBe(['conflict', 'invalid-rule', 'invalid-rule', 'invalid-rule'])
            ->and(id_codes($owners->stale()))->toBe(['stale-rule'])
            ->and($owners->stale()[0]['message'])->toContain('exact:a:gone');
    });

    it('classifies a hand-written format by its own owner', function (): void {
        $owners = IdentityOwners::fromArray('protocol', ['rules' => [], 'formats' => [['id' => 'format:x', 'owner' => 'media', 'why' => 'w', 'format' => 'f', 'source' => ['file' => 'f.php', 'symbol' => 'X']]]]);

        expect($owners->resolve('format:x'))->toBe('media')->and($owners->stale())->toBe([]);
    });

    it('turns an identity without a rule into an error and sorts and wraps the rest', function (): void {
        $owners = IdentityOwners::fromArray('x', ['rules' => [['match' => 'prefix', 'value' => 'k:', 'owner' => 'drive', 'why' => 'w']]]);
        $source = ['file' => 'f.php', 'symbol' => 'S'];
        $built = Identities::assemble('x', [
            ['id' => 'k:b', 'source' => $source],
            ['id' => 'k:a', 'source' => $source, '_owner_key' => 'k:other'],
            ['id' => 'z:orphan', 'source' => $source],
            ['id' => 'k:b', 'source' => $source],
        ], $owners);

        expect(array_column($built['data']['items'], 'id'))->toBe(['k:a', 'k:b'])
            ->and($built['data']['items'][0])->not->toHaveKey('_owner_key')
            ->and($built['data'])->toMatchArray(['version' => 1, 'base' => '35718fd2', 'kind' => 'x', 'changes' => []])
            ->and(id_codes($built['problems']))->toBe(['duplicate-id', 'unclassified'])
            ->and(Identities::exitCode($built['problems']))->toBe(2);
    });
});

describe('ledger', function (): void {
    it('accepts records that match the items and reports stale or malformed ones', function (): void {
        $items = ['k:new' => [], 'k:changed' => [], 'k:renamed' => []];
        $record = static fn (string $id, string $change, array $extra = []): array => $extra + ['kind' => 'x', 'id' => $id, 'change' => $change, 'reason' => 'r', 'decision' => 'D22'];

        expect(Identities::checkChanges('x', [
            $record('k:new', 'added'),
            $record('k:changed', 'redefined'),
            $record('k:gone', 'removed'),
            $record('k:renamed', 'renamed', ['from' => 'k:old']),
        ], $items))->toBe([])
            ->and(id_codes(Identities::checkChanges('x', [
                $record('k:missing', 'added'),
                $record('k:new', 'removed'),
                $record('k:renamed', 'renamed', ['from' => 'k:new']),
                $record('k:renamed', 'renamed'),
                $record('k:new', 'moved'),
                $record('k:new', 'added', ['decision' => 'owner']),
                $record('k:new', 'added', ['reason' => ' ']),
            ], $items)))->toBe(['invalid-change', 'invalid-change', 'invalid-change', 'stale-change', 'stale-change', 'stale-change', 'stale-change']);
    });
});

describe('schema extractor', function (): void {
    it('records what follows the id strategy as variants and nothing else', function (): void {
        $repo = id_schema_repo(static fn (string $ids, string $hostKeys, string $prefix): array => [
            'name' => 'owner_id',
            'type' => $ids === 'bigint' ? 'bigint' : 'character(26)',
            'not_null' => false,
            'default' => null,
        ]);

        try {
            $built = Schema::build($repo);
            $column = array_values(array_filter($built['data']['items'], static fn (array $i): bool => $i['id'] === 'column:v_things.owner_id'))[0];

            expect($built['problems'])->toBe([])
                ->and($column['owner'])->toBe('drive')
                ->and($column['engines']['pgsql']['type'])->toBe('bigint')
                ->and($column['variants']['pgsql']['axes'])->toBe(['ids'])
                ->and($column['variants']['pgsql']['by'])->toBe(['ids=ulid' => ['type' => 'character(26)'], 'ids=uuid7' => ['type' => 'character(26)']])
                ->and($built['data']['presets'])->toBe(48);
        } finally {
            id_remove($repo->root());
        }
    });

    it('treats a difference that follows the table prefix as drift (exit 1)', function (): void {
        $repo = id_schema_repo(static fn (string $ids, string $hostKeys, string $prefix): array => [
            'name' => 'owner_id',
            'type' => $prefix === 'v' ? 'bigint' : 'text',
            'not_null' => false,
            'default' => null,
        ]);

        try {
            $built = Schema::build($repo);

            expect(id_codes($built['problems']))->toContain('unexpected-difference')
                ->and(Identities::exitCode($built['problems']))->toBe(1)
                ->and(implode("\n", Identities::describe($built['problems'])))->toContain('table_prefix');
        } finally {
            id_remove($repo->root());
        }
    });

    it('folds the custom_ prefix into v_ and notes a column the original snapshot does not have', function (): void {
        $repo = id_schema_repo(static fn (string $ids, string $hostKeys, string $prefix, string $tenancy, string $kind): array => [
            'name' => $kind === 'expected' ? 'new_column' : 'old_column',
            'type' => 'bigint',
            'not_null' => true,
            'default' => "nextval('{$prefix}_things_id_seq'::regclass)",
        ]);

        try {
            $built = Schema::build($repo);
            $table = array_values(array_filter($built['data']['items'], static fn (array $i): bool => $i['id'] === 'table:v_things'))[0];
            $column = array_values(array_filter($built['data']['items'], static fn (array $i): bool => $i['id'] === 'column:v_things.new_column'))[0];

            expect($built['problems'])->toBe([])
                ->and($column['engines']['pgsql']['default'])->toContain('v_things_id_seq')
                ->and($table['original_diff']['pgsql']['columns']['bigint.string.v.off'])->toBe(['added' => ['new_column'], 'removed' => ['old_column']]);
        } finally {
            id_remove($repo->root());
        }
    });
});

describe('config extractor', function (): void {
    it('normalizes defaults to JSON-able data', function (): void {
        $evaluate = static function (string $code): mixed {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php return '.$code.';') ?? [];

            return Config::evaluate($ast[0]->expr);
        };

        expect($evaluate("env('A', 5)"))->toBe(['default' => 5, 'env' => 'A'])
            ->and($evaluate("env('A')"))->toBe(['env' => 'A'])
            ->and($evaluate("env('A', env('B', 24))"))->toBe(['default' => ['default' => 24, 'env' => 'B'], 'env' => 'A'])
            ->and($evaluate('-1.5'))->toBe(-1.5)
            ->and($evaluate('5_368_709_120'))->toBe(5368709120)
            ->and($evaluate("['a', null, true]"))->toBe(['a', null, true])
            ->and($evaluate('[]'))->toBe([])
            ->and($evaluate('60 * 60'))->toBe(['expr' => '60 * 60']);
    });
});

describe('http extractor', function (): void {
    it('attributes routes to the flag that mounts or unmounts them', function (): void {
        $route = static fn (string $name, array $extra = []): array => $extra + ['name' => $name, 'methods' => ['GET'], 'uri' => 'api/x/'.$name, 'middleware' => ['api'], 'action' => 'C'];
        $merged = Http::merge([
            '' => [$route('a'), $route('hooks')],
            'opt.in' => [$route('a'), $route('hooks'), $route('extra')],
            'on.by.default' => [$route('a')],
        ], ['opt.in' => false, 'on.by.default' => true]);

        expect($merged['problems'])->toBe([])
            ->and(array_keys($merged['routes']))->toBe(['a', 'hooks', 'extra'])
            ->and($merged['requires'])->toBe(['extra' => ['opt.in'], 'hooks' => ['on.by.default']]);
    });

    it('treats a run that changes a route besides mounting it as drift (exit 1)', function (): void {
        $route = static fn (array $extra = []): array => $extra + ['name' => 'a', 'methods' => ['GET'], 'uri' => 'api/x/a', 'middleware' => ['api'], 'action' => 'C'];
        $merged = Http::merge(['' => [$route()], 'flag' => [$route(['middleware' => ['api', 'injected']])]], ['flag' => false]);

        expect(id_codes($merged['problems']))->toBe(['flag-changes-route'])
            ->and($merged['problems'][0]['exit'])->toBe(1);
    });
});

describe('protocol formats', function (): void {
    it('accepts a format whose symbol exists and rejects a missing method, class or file', function (): void {
        $source = ['packages/core/src/Http/Support/Cursor.php' => '<?php namespace Vaulter\Http\Support; final class Cursor { public const string PREFIX = "c"; public static function encode(): string { return ""; } }'];
        $index = SourceIndex::fromSources($source, ['Vaulter\\' => 'core'], []);
        $repo = Repo::at(sys_get_temp_dir().'/vaulter-identities-formats-'.bin2hex(random_bytes(6)));
        $repo->write('packages/core/src/Http/Support/Cursor.php', $source['packages/core/src/Http/Support/Cursor.php']);
        $format = static fn (string $symbol, string $file = 'packages/core/src/Http/Support/Cursor.php'): array => ['id' => 'format:cursor', 'owner' => 'core', 'format' => 'f', 'why' => 'w', 'source' => ['file' => $file, 'symbol' => $symbol]];

        try {
            expect(Protocol::checkFormat($index, $repo, $format('Vaulter\Http\Support\Cursor::encode')))->toBeNull()
                ->and(Protocol::checkFormat($index, $repo, $format('Vaulter\Http\Support\Cursor::PREFIX')))->toBeNull()
                ->and(Protocol::checkFormat($index, $repo, $format('Vaulter\Http\Support\Cursor')))->toBeNull()
                ->and(Protocol::checkFormat($index, $repo, $format('Vaulter\Http\Support\Cursor::decode'))['code'])->toBe('format-source')
                ->and(Protocol::checkFormat($index, $repo, $format('Vaulter\Http\Support\Nope'))['code'])->toBe('format-source')
                ->and(Protocol::checkFormat($index, $repo, $format('Vaulter\Http\Support\Cursor', 'packages/core/src/Missing.php'))['code'])->toBe('format-source')
                ->and(Protocol::checkFormat($index, $repo, ['id' => 'cursor'])['code'])->toBe('invalid-format');
        } finally {
            id_remove($repo->root());
        }
    });
});

describe('committed fixtures', function (): void {
    it('are what the generators produce from the owners files and the code', function (string $kind): void {
        $generator = require dirname(__DIR__, 3).'/scripts/inventory/generators/identities-'.$kind.'.php';
        $outcome = $generator->check(Repo::discover());

        expect(implode("\n", $outcome['lines']))->toBe('')->and($outcome['code'])->toBe(0);
    })->with(Identities::KINDS);

    it('have the file format: base, the ledger records of the kind, items sorted by id with an owner and a source', function (string $kind): void {
        $fixture = id_fixture($kind);
        $ids = array_column($fixture['items'], 'id');
        $sorted = $ids;
        usort($sorted, strcmp(...));

        expect($fixture)->toMatchArray(['version' => 1, 'base' => '35718fd2', 'kind' => $kind, 'changes' => Identities::ledger(Repo::discover(), $kind)])
            ->and($ids)->toBe($sorted)
            ->and(array_unique($ids))->toBe($ids);
        foreach ($fixture['items'] as $item) {
            expect(TargetMap::OWNERS)->toContain($item['owner']);
            expect($item['source'])->toHaveKeys(['file', 'symbol']);
            expect(Repo::discover()->exists($item['source']['file']))->toBeTrue($item['id'].' -> '.$item['source']['file']);
        }
    })->with(Identities::KINDS);

    it('cite only accepted decisions in the ledger', function (): void {
        $ledger = Repo::discover()->readJson(Identities::LEDGER);

        expect($ledger['changes'])->not->toBe([]);
        foreach ($ledger['changes'] as $change) {
            expect(Identities::KINDS)->toContain($change['kind'])
                ->and(ID_ACCEPTED_DECISIONS)->toContain($change['decision']);
        }
    });

    it('hold no absolute path and no line number', function (string $kind): void {
        $text = (string) file_get_contents(Repo::discover()->path(Identities::fixture($kind)));

        expect($text)->not->toContain(dirname(__DIR__, 3))
            ->and($text)->not->toMatch('/"line":\s*\d/');
    })->with(Identities::KINDS);

    it('give every table, column, index, constraint and foreign key of the schema an owner of its table', function (): void {
        $schema = id_fixture('schema');
        $tables = [];
        foreach ($schema['items'] as $item) {
            if (str_starts_with($item['id'], 'table:')) {
                $tables[$item['table'] ?? substr($item['id'], 6)] = $item['owner'];
            }
        }
        $migrations = array_map(static fn (string $p): string => basename($p), glob(Repo::discover()->path('packages/*/database/migrations').'/*.php') ?: []);
        sort($migrations, SORT_STRING);

        expect($schema['presets'])->toBe(48)
            ->and($schema['engines'])->toBe(['pgsql', 'mysql', 'sqlite'])
            ->and(count($tables))->toBe(33)
            ->and($tables['v_collaboration_heads'] ?? null)->toBe('collaboration')
            ->and($tables['v_collaboration_permission_versions'] ?? null)->toBe('collaboration')
            ->and(array_column(array_filter($schema['items'], static fn (array $i): bool => str_starts_with($i['id'], 'migration:')), 'id'))->toBe(array_map(static fn (string $b): string => 'migration:'.$b, $migrations));
        foreach ($schema['items'] as $item) {
            if (isset($item['table'])) {
                expect($item['owner'])->toBe($tables[$item['table']], $item['id']);
            }
        }
    });

    it('record the engines and the foreign keys of the canonical preset', function (): void {
        $items = array_column(id_fixture('schema')['items'], null, 'id');

        expect($items['fk:v_nodes.v_nodes_drive_id_foreign']['engines'])->toHaveKeys(['pgsql', 'mysql'])
            ->and($items['fk:v_nodes.v_nodes_drive_id_foreign']['engines']['pgsql'])->toMatchArray(['columns' => ['drive_id'], 'ref_table' => 'v_drives', 'ref_columns' => ['id'], 'on_delete' => 'CASCADE'])
            ->and($items['fk:v_nodes.v_nodes_drive_id_foreign'])->not->toHaveKey('engine_diff')
            ->and($items['column:v_nodes.drive_id']['engines'])->toHaveKeys(['pgsql', 'mysql', 'sqlite'])
            ->and($items['column:v_nodes.drive_id']['variants']['pgsql']['axes'])->toBe(['ids'])
            ->and($items['table:v_blobs']['owner'])->toBe('media')
            ->and($items['table:v_outbox']['owner'])->toBe('core')
            ->and($items['table:v_webhook_endpoints']['owner'])->toBe('webhooks');
    });

    it('list config keys with defaults and the environment variables that feed them', function (): void {
        $items = array_column(id_fixture('config')['items'], null, 'id');

        expect($items['config:vaulter.database.table_prefix'])->toMatchArray(['default' => ['default' => 'v_', 'env' => 'VAULTER_TABLE_PREFIX'], 'env' => 'VAULTER_TABLE_PREFIX', 'registered_defaults' => true, 'owner' => 'core'])
            ->and($items['config:vaulter-media.dedup.scope']['owner'])->toBe('media')
            ->and($items['env:VAULTER_TABLE_PREFIX']['config_keys'])->toBe(['vaulter.database.table_prefix'])
            ->and($items['env:VAULTER_ORPHAN_GRACE_HOURS']['config_keys'])->toBe(['vaulter-media.gc.orphan_grace_hours'])
            ->and($items['config:vaulter-media.models.blob']['owner'])->toBe('media')
            ->and($items)->not->toHaveKey('config:vaulter.models.blob')
            ->and($items['publish:vaulter-config'])->toMatchArray(['owner' => 'core', 'via' => 'module:config'])
            ->and($items['config:vaulter.modules'])->toMatchArray(['default' => [], 'owner' => 'core'])
            ->and($items)->not->toHaveKey('config:vaulter.features.webhooks')
            ->and($items)->toHaveKeys(['publish:vaulter-media-config', 'publish:vaulter-drive-translations', 'publish:vaulter-azgard-config']);
    });

    it('parse the signatures of the commands', function (): void {
        $items = array_column(id_fixture('cli')['items'], null, 'id');
        $doctor = $items['command:vaulter:doctor'];

        expect(count($items))->toBe(35)
            ->and($items['command:vaulter:operations']['owner'])->toBe('core')
            ->and($items['command:vaulter:collaboration:prune']['owner'])->toBe('collaboration')
            ->and($items['command:vaulter:collaboration:drain']['owner'])->toBe('collaboration')
            ->and(array_column($items['command:vaulter:collaboration:drain']['options'], 'default', 'name'))->toBe(['deadline' => '30', 'release' => false])
            ->and($doctor['parse'])->toBe('signature')
            ->and(array_column($doctor['options'], 'name'))->toBe(['production', 'json', 'check', 'sample', 'probe-storage'])
            ->and(array_column($doctor['options'], 'accepts_value', 'name')['sample'])->toBeTrue()
            ->and(array_column($doctor['options'], 'array', 'name')['check'])->toBeTrue()
            ->and($items['command:vaulter:changes:reset']['arguments'][0])->toMatchArray(['name' => 'drive', 'required' => true])
            ->and($items['command:vaulter:blobs:gc']['owner'])->toBe('media')
            ->and($items['command:vaulter:relay']['owner'])->toBe('core');
    });

    it('list the routes with the feature flag that mounts them and their place in the OpenAPI contract', function (): void {
        $http = id_fixture('http');
        $items = array_column($http['items'], null, 'id');

        expect($http['flags'])->toHaveKeys(['vaulter.modules.webhooks', 'vaulter-drive.features.share_links', 'vaulter.modules.collaboration'])
            ->and($http['flags'])->not->toHaveKey('vaulter.features.webhooks')
            ->and($items['route:vaulter.v1.webhook-endpoints.index']['requires'])->toBe(['vaulter.modules.webhooks'])
            ->and($items['route:vaulter.v1.webhook-endpoints.index']['default_registered'] ?? false)->toBeFalse()
            ->and($items['route:vaulter.v1.webhook-endpoints.index']['middleware'])->toContain('vaulter.module:webhooks')
            ->and($items['route:vaulter.v1.collaboration.hub.load']['requires'])->toBe(['vaulter.modules.collaboration'])
            ->and($items['route:vaulter.v1.collaboration.hub.load']['default_registered'] ?? false)->toBeFalse()
            ->and($items['route:vaulter.v1.grants.index'])->toMatchArray(['methods' => ['GET'], 'owner' => 'drive', 'openapi' => ['path' => '/nodes/{node}/grants', 'operation_id' => 'grants.index']])
            ->and($items['route:vaulter.v1.grants.index']['requires'] ?? [])->toBe([])
            ->and($items['route:vaulter.v1.documents.collaboration.session']['owner'])->toBe('collaboration')
            ->and($items['route:vaulter.v1.documents.collaboration.session'])->not->toHaveKey('class_owner')
            ->and($items['route:vaulter.v1.uploads.tus.head']['methods'])->toBe(['HEAD'])
            ->and(array_filter($http['items'], static fn (array $i): bool => str_starts_with($i['id'], 'openapi:') || ($i['route_only'] ?? false)))->toBe([]);
    });

    it('list the event types, problem codes, queues, casts and hand-written formats', function (): void {
        $items = array_column(id_fixture('protocol')['items'], null, 'id');
        $count = static fn (string $prefix): int => count(array_filter(array_keys($items), static fn (string $id): bool => str_starts_with($id, $prefix)));

        expect($count('event:'))->toBe(33)
            ->and($items['event:drive.access.effective_permissions_changed'])->toMatchArray(['owner' => 'drive', 'subject' => 'access', 'sinks' => ['activity' => false, 'external' => false, 'journal' => null]])
            ->and($count('problem:'))->toBe(85)
            ->and($items['problem:stale_write'])->toMatchArray(['status' => 412, 'owner' => 'core', 'retryable' => 'after_refetch', 'receipt' => 'cacheable'])
            ->and($items['problem:collaboration_draining'])->toMatchArray(['status' => 503, 'owner' => 'collaboration', 'retryable' => 'with_backoff', 'receipt' => 'never'])
            ->and($items['problem:collaboration_checkpoint_conflict'])->toMatchArray(['status' => 409, 'owner' => 'collaboration', 'retryable' => 'after_refetch', 'receipt' => 'never'])
            ->and($items['problem:collaboration_update_too_large'])->toMatchArray(['status' => 413, 'owner' => 'collaboration', 'retryable' => 'never', 'receipt' => 'never'])
            ->and($items['problem:document_state_pending'])->toMatchArray(['status' => 409, 'owner' => 'documents', 'retryable' => 'with_backoff', 'receipt' => 'never'])
            ->and($items['problem:blob_unavailable'])->toMatchArray(['status' => 409, 'owner' => 'media', 'retryable' => 'never', 'receipt' => 'never'])
            ->and($items['event:file.uploaded']['sinks'])->toBe(['activity' => true, 'external' => true, 'journal' => 'upsert'])
            ->and($items['event:file.uploaded']['data'])->toHaveKey('revision_id')
            ->and($items['problem:node_not_found'])->toMatchArray(['status' => 404, 'owner' => 'drive', 'retryable' => 'never', 'receipt' => 'never'])
            ->and($items['problem:node_sequence_mismatch'])->toMatchArray(['status' => 412, 'retryable' => 'after_refetch', 'receipt' => 'cacheable'])
            ->and($items['problem:operation_in_progress'])->toMatchArray(['status' => 409, 'owner' => 'core', 'retryable' => 'with_backoff', 'receipt' => 'never'])
            ->and($items['problem:rate_limited']['owner'])->toBe('core')
            ->and($items['problem:permission_denied']['owner'])->toBe('core')
            ->and($items['serialized:queue:Vaulter\Webhooks\Jobs\DeliverWebhook']['owner'])->toBe('webhooks')
            ->and($items['serialized:config:vaulter-drive.models.node']['fqcns'])->toBe(['Vaulter\Drive\Models\Node'])
            ->and($items['format:cas-key']['manual'])->toBeTrue()
            ->and($items['format:jwt-claims-room']['owner'])->toBe('collaboration');
    });

    it('fail loudly when the owners file is unusable', function (): void {
        expect(static fn () => IdentityOwners::fromArray('x', ['rules' => 'nope']))->toThrow(InventoryError::class);
    });
});
