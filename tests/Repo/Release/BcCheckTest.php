<?php

declare(strict_types=1);

use Vaulter\Inventory\InventoryError;
use Vaulter\Inventory\Surface;
use Vaulter\Release\Contract\Change;
use Vaulter\Release\Contract\ContractError;
use Vaulter\Release\Contract\Identities;
use Vaulter\Release\Contract\NpmSurface;
use Vaulter\Release\Contract\PhpSurface;
use Vaulter\Release\Contract\ReleaseBases;
use Vaulter\Release\Contract\ReleaseCheck;
use Vaulter\Release\Contract\SchemaDdl;
use Vaulter\Release\Contract\SnapshotManifest;
use Vaulter\Release\Contract\SourceReader;
use Vaulter\Release\Contract\TrustPolicy;

/*
 * The structural adapters of the BC check: the PHP surface
 * from surface.json, the identities and the schema DDL, each compared by stable key, and the snapshot manifest with its exit-2
 * inputs. The `php:` cases build surface.json with the same reader as the generator (Surface::fromSources); the `identities:`
 * and `schema:` cases mutate the committed generated fixtures. A green adapter proves the signature guard only — no runtime,
 * consumer or upgrade qualification. Release bases, exceptions, the CLI and npm are covered by their own tests.
 */

require_once dirname(__DIR__, 3).'/scripts/inventory/bootstrap.php';
require_once dirname(__DIR__, 3).'/scripts/inventory/lib/Surface.php';
foreach (glob(dirname(__DIR__, 3).'/scripts/release/Contract/*.php') ?: [] as $bcFile) {
    require_once $bcFile;
}

function bc_root(): string
{
    return dirname(__DIR__, 3);
}

/**
 * One synthetic source file of the package core, with the surface attributes imported.
 *
 * @return array<string, string>
 */
function bc_src(string $code): array
{
    return ['packages/core/src/Fixture.php' => "<?php\nnamespace Vaulter;\nuse Packman\\Attributes\\Api;\nuse Packman\\Attributes\\Spi;\nuse Packman\\Attributes\\SpiKind;\nuse Packman\\Attributes\\Internal;\n".$code];
}

/**
 * The types of a revision as the adapter reads them: surface.json written by the reader, decoded and validated.
 *
 * @param  array<string, string>               $sources
 * @return array<string, array<string, mixed>>
 */
function bc_types(array $sources): array
{
    $documents = [];
    foreach (Surface::structures(Surface::fromSources($sources)) as $package => $json) {
        $documents[Surface::structurePath($package)] = $json;
    }

    return PhpSurface::load($documents);
}

/** @return list<string> "CLASSIFICATION subject rule" of the changes from one code to another */
function bc_php(string $before, string $after): array
{
    return bc_rows(PhpSurface::compare('php:core', bc_types(bc_src($before)), bc_types(bc_src($after))));
}

/**
 * @param  list<array<string, mixed>> $changes
 * @return list<string>
 */
function bc_rows(array $changes): array
{
    return array_map(static fn (array $c): string => "{$c['classification']} {$c['subject']} {$c['rule']}", $changes);
}

/** Exit code of a tiny PHP script in its own process, without Laravel, the package autoload or a database. */
function bc_run_php(string $code): int
{
    $file = tempnam(sys_get_temp_dir(), 'bc-oracle-');
    file_put_contents($file, $code);
    exec(escapeshellarg(PHP_BINARY).' -n -d display_errors=stderr '.escapeshellarg($file).' 2>&1', $output, $exit);
    unlink($file);

    return $exit;
}

/** 2 when reading an input throws ContractError (the exit of an untrusted input), else 0. */
function bc_exit(callable $read): int
{
    try {
        $read();
    } catch (ContractError) {
        return 2;
    }

    return 0;
}

/** @return list<string> the owners of the committed manifest's release lines */
function bc_owners(): array
{
    $manifest = SnapshotManifest::parse((string) file_get_contents(bc_root().'/'.SnapshotManifest::PATH));

    return array_values(array_unique(array_merge(...array_values($manifest['release_lines']))));
}

/** @return array<string, mixed> a committed identities fixture, decoded */
function bc_identities(string $kind): array
{
    return json_decode((string) file_get_contents(bc_root()."/tests/Repo/Arch/Fixtures/identities/{$kind}.json"), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed> $before
 * @param  array<string, mixed> $after
 * @return list<string>
 */
function bc_identity_rows(string $kind, array $before, array $after): array
{
    $load = static fn (array $data): array => Identities::load(json_encode($data, JSON_THROW_ON_ERROR), $kind, bc_owners(), "identities/{$kind}.json");

    return bc_rows(Identities::compare("identities:{$kind}", $load($before), $load($after)));
}

/** @param array<string, mixed> $data */
function bc_item(array $data, string $id): int
{
    foreach ($data['items'] as $i => $item) {
        if ($item['id'] === $id) {
            return $i;
        }
    }
    throw new RuntimeException("fixture item {$id} not found");
}

/** @return array<string, mixed> the committed SQLite DDL snapshot of the canonical preset */
function bc_ddl(): array
{
    return json_decode((string) file_get_contents(bc_root().'/packages/core/tests/Fixtures/Schema/expected/sqlite/bigint.string.v.off.json'), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed> $before
 * @param  array<string, mixed> $after
 * @return list<string>
 */
function bc_ddl_rows(array $before, array $after): array
{
    $load = static fn (array $data): array => SchemaDdl::load(json_encode($data, JSON_THROW_ON_ERROR), 'expected/sqlite/bigint.string.v.off.json');

    return bc_rows(SchemaDdl::compare('schema:sqlite', $load($before), $load($after)));
}

/** A scratch root with the given files, for the manifest and the source reader. */
function bc_scratch(array $files): string
{
    $root = sys_get_temp_dir().'/bc-scratch-'.bin2hex(random_bytes(6));
    foreach ($files as $path => $bytes) {
        @mkdir(dirname("{$root}/{$path}"), 0777, true);
        file_put_contents("{$root}/{$path}", $bytes);
    }
    @mkdir($root, 0777, true);

    return $root;
}

/** @param array<string, mixed> $override */
function bc_manifest(array $override = [], ?array $surfaces = null): string
{
    $surface = ['id' => 'php:core', 'adapter' => 'php-surface', 'schema_version' => 1, 'owner' => 'core', 'release_line' => 'php-public', 'inputs' => ['packages/core/surface.json'], 'required' => true];

    return json_encode(['format' => 'vaulter.contract-snapshots', 'version' => 1, 'release_lines' => ['php-public' => ['owners' => ['core']]], 'surfaces' => $surfaces ?? [array_merge($surface, $override)]], JSON_THROW_ON_ERROR);
}

// --- PHP surface ---------------------------------------------------------------------------------------------------------

it('php: removing A::send while an identical B::send stays is a BREAK of A::send, which a set of signature lines cannot see (case 1)', function (): void {
    $before = "#[Api] final class A { public function send(string \$key): void {} }\n#[Api] final class B { public function send(string \$key): void {} }";
    $after = "#[Api] final class A {}\n#[Api] final class B { public function send(string \$key): void {} }";
    $lines = static fn (string $code): array => array_values(array_unique(array_filter(explode("\n", Surface::manifests(Surface::fromSources(bc_src($code)), ['core'])['core']), static fn (string $l): bool => str_starts_with($l, '  '))));

    expect(bc_php($before, $after))->toBe(['BREAK Vaulter\A::send() member-removed'])
        ->and(Change::exitCode(PhpSurface::compare('php:core', bc_types(bc_src($before)), bc_types(bc_src($after)))))->toBe(1)
        ->and(array_diff($lines($before), $lines($after)))->toBe([]);
});

it('php: a new method of an implementer SPI is a BREAK, a concrete method of a final Api class is ADD, a new enum case and a member of an extensible type or trait are REVIEW (case 2)', function (): void {
    expect(bc_php(
        '#[Spi(SpiKind::Implement)] interface Port { public function read(): string; }',
        '#[Spi(SpiKind::Implement)] interface Port { public function read(): string; public function write(string $text): void; }',
    ))->toBe(['BREAK Vaulter\Port::write() spi-obligation-added'])
        ->and(bc_php('#[Api] final class F { public function a(): void {} }', '#[Api] final class F { public function a(): void {} public function b(): int { return 1; } }'))
        ->toBe(['ADD Vaulter\F::b() member-added'])
        ->and(Change::exitCode(PhpSurface::compare('php:core', bc_types(bc_src('#[Api] final class F {}')), bc_types(bc_src('#[Api] final class F { public function b(): void {} }')))))->toBe(0)
        ->and(bc_php("#[Api] enum E: string { case A = 'a'; }", "#[Api] enum E: string { case A = 'a'; case B = 'b'; }"))
        ->toBe(['REVIEW Vaulter\E::B enum-case-added'])
        ->and(bc_php('#[Api] class Open {}', '#[Api] class Open { public function more(): void {} }'))
        ->toBe(['REVIEW Vaulter\Open::more() member-added-extensible'])
        ->and(bc_php('#[Api] trait T {}', '#[Api] trait T { public function more(): void {} }'))
        ->toBe(['REVIEW Vaulter\T::more() member-added-extensible'])
        ->and(bc_php('#[Api] final class F {}', "#[Api] final class F {}\n#[Api] final class G {}"))
        ->toBe(['ADD Vaulter\G type-added']);
});

it('php: a renamed or new required parameter, a changed by-ref return, static, final or visibility and a public member or type turned Internal are each a BREAK (case 3)', function (): void {
    $base = '#[Api] class Svc { public function run(string $key): void {} public function &read(): string { static $s = ""; return $s; } public function at(): void {} public function open(): void {} }';
    $variants = [
        'BREAK Vaulter\Svc::run($key) parameter-renamed' => ['string $key', 'string $name'],
        'BREAK Vaulter\Svc::run($n) required-parameter-added' => ['string $key)', 'string $key, int $n)'],
        'BREAK Vaulter\Svc::read() return-by-ref' => ['function &read', 'function read'],
        'BREAK Vaulter\Svc::at() static' => ['public function at', 'public static function at'],
        'BREAK Vaulter\Svc::open() final-added' => ['public function open', 'final public function open'],
        'BREAK Vaulter\Svc::open() visibility-reduced' => ['public function open', 'protected function open'],
        'BREAK Vaulter\Svc::open() member-internal' => ['public function open', '#[Internal] public function open'],
        // An Internal type no root inherits from is not in the graph: hiding it reads as the root being gone.
        'BREAK Vaulter\Svc type-removed' => ['#[Api] class Svc', '#[Internal] class Svc'],
    ];
    foreach ($variants as $expected => [$from, $to]) {
        expect(bc_php($base, str_replace($from, $to, $base)))->toBe([$expected], $expected);
    }
    $child = "{$base}\n#[Api] final class Leaf extends Svc {}";

    expect(bc_php($child, str_replace('#[Api] class Svc', '#[Internal] class Svc', $child)))->toBe(['BREAK Vaulter\Svc type-hidden']);
});

it('php: the compiler oracle confirms in a separate process that a renamed parameter breaks a named-argument caller and a by-ref return breaks an implementer', function (): void {
    $caller = 'function run(string $%s): void {} run(key: "a"); echo "ok";';
    $implementer = 'interface Port { public function %sread(): string; } final class Impl implements Port { public function read(): string { return ""; } } echo "ok";';

    expect(bc_run_php('<?php '.sprintf($caller, 'key')))->toBe(0)
        ->and(bc_run_php('<?php '.sprintf($caller, 'name')))->not->toBe(0)
        ->and(bc_run_php('<?php '.sprintf($implementer, '')))->toBe(0)
        ->and(bc_run_php('<?php '.sprintf($implementer, '&')))->not->toBe(0)
        ->and(bc_php('#[Spi(SpiKind::Implement)] interface Port { public function read(): string; }', '#[Spi(SpiKind::Implement)] interface Port { public function &read(): string; }'))
        ->toBe(['BREAK Vaulter\Port::read() return-by-ref']);
});

it('php: a changed member of an Internal base or trait is visible at the exported child, through alias and insteadof; the Internal ancestor stays in the graph (case 4)', function (): void {
    $code = <<<'PHP'
        #[Internal] abstract class Base { public function read(string $key): string { return $key; } }
        #[Internal] trait Greets { public function greet(): string { return ''; } }
        #[Internal] trait Loud { public function say(): string { return ''; } }
        #[Internal] trait Quiet { public function say(): string { return ''; } }
        #[Internal] final class Unrelated { public function x(): void {} }
        #[Api] final class Child extends Base { use Greets { greet as hello; } use Loud, Quiet { Loud::say insteadof Quiet; } }
        PHP;
    $types = bc_types(bc_src($code));

    expect($types)->toHaveKeys(['Vaulter\Base', 'Vaulter\Greets', 'Vaulter\Loud', 'Vaulter\Quiet'])
        ->and($types)->not->toHaveKey('Vaulter\Unrelated')
        ->and($types['Vaulter\Base']['surface'])->toBe('Internal')
        ->and(array_keys(PhpSurface::resolve($types, 'Vaulter\Child')['members']))->toBe(['method:greet', 'method:hello', 'method:read', 'method:say'])
        ->and(bc_php($code, str_replace('read(string $key)', 'read(int $key)', $code)))->toBe(['REVIEW Vaulter\Child::read($key) parameter-type'])
        ->and(bc_php($code, str_replace("greet(): string { return ''; }", 'greet(): int { return 0; }', $code)))->toBe(['REVIEW Vaulter\Child::greet() return-type', 'REVIEW Vaulter\Child::hello() return-type'])
        ->and(bc_php($code, str_replace("trait Quiet { public function say(): string { return ''; }", 'trait Quiet { public function say(): int { return 0; }', $code)))->toBe([])
        ->and(bc_php($code, str_replace("trait Loud { public function say(): string { return ''; }", 'trait Loud { public function say(): int { return 0; }', $code)))->toBe(['REVIEW Vaulter\Child::say() return-type'])
        ->and(bc_php($code, str_replace('public function read(string $key): string { return $key; }', '', $code)))->toBe(['BREAK Vaulter\Child::read() member-removed']);
});

it('php: an unknown Vaulter ancestor is REVIEW, unparsable code and an unknown structural field are errors, never green (case 4)', function (): void {
    $external = '#[Api] final class Ext extends \Vaulter\Elsewhere\Base {}';
    $documents = [];
    foreach (Surface::structures(Surface::fromSources(bc_src('#[Api] final class F { public function a(): void {} }'))) as $package => $json) {
        $documents[Surface::structurePath($package)] = str_replace('"by_ref": false,', '"by_ref": false, "variance": "co",', $json);
    }

    expect(bc_php($external, $external))->toBe(['REVIEW Vaulter\Ext ancestor-unresolved'])
        ->and(fn () => Surface::fromSources(bc_src('#[Api] final class Broken { public function }')))->toThrow(InventoryError::class)
        ->and(bc_exit(static fn (): array => PhpSurface::load($documents)))->toBe(2)
        ->and(bc_exit(static fn (): array => PhpSurface::load(['core/surface.json' => '{"format":"vaulter.surface","version":2,"package":"core","types":{}}'])))->toBe(2);
});

it('php: the normal form ignores union order and self spelling, keeps a promoted property behind an Internal constructor, and does not take @internal for #[Internal]', function (): void {
    $wired = '#[Api] final class Wired { #[Internal] public function __construct(public readonly string $id) {} public function id(): string { return $this->id; } }';

    expect(bc_php('#[Api] final class U { public function a(int|string $v): ?self { return null; } }', '#[Api] final class U { public function a(string|int $v): null|U { return null; } }'))->toBe([])
        ->and(bc_php($wired, str_replace('public readonly string $id', 'private readonly string $id', $wired)))->toBe(['BREAK Vaulter\Wired::$id member-removed'])
        ->and(bc_php('#[Api] final class D { public function a(): void {} }', '#[Api] final class D { /** @internal */ public function a(): void {} }'))->toBe(['REVIEW Vaulter\D::a() doc-internal'])
        ->and(bc_php('#[Api] final class D { /** @return list<int> */ public function a(): array { return []; } }', '#[Api] final class D { /** @return list<string> */ public function a(): array { return []; } }'))->toBe(['REVIEW Vaulter\D::a() phpdoc'])
        ->and(bc_php('#[Api] final class D { public function a(int $n = 1): void {} }', '#[Api] final class D { public function a(int $n = 2): void {} }'))->toBe(['REVIEW Vaulter\D::a($n) parameter-default']);
});

// --- identities ----------------------------------------------------------------------------------------------------------

it('identities: reordered items, changed source refs, ledger and base of every committed kind are no change; a duplicate id, an unknown field or an owner outside the inventory is exit 2 (case 5)', function (): void {
    foreach (['cli', 'config', 'http', 'protocol', 'schema'] as $kind) {
        $before = bc_identities($kind);
        $after = $before;
        $after['items'] = array_reverse(array_map(static fn (array $i): array => isset($i['source']) ? array_merge($i, ['source' => ['file' => 'moved.php', 'symbol' => 'x']]) : $i, $after['items']));
        $after['changes'] = [];
        $after['base'] = 'ffffffff';

        expect(bc_identity_rows($kind, $before, $after))->toBe([], $kind);
    }
    $protocol = bc_identities('protocol');
    $load = static fn (array $data): int => bc_exit(static fn (): array => Identities::load(json_encode($data, JSON_THROW_ON_ERROR), 'protocol', bc_owners(), 'protocol.json'));
    $duplicate = $protocol;
    $duplicate['items'][] = $protocol['items'][0];
    $unknown = $protocol;
    $unknown['items'][0]['optional'] = true;
    $stranger = $protocol;
    $stranger['items'][0]['owner'] = 'host';

    expect($load($protocol))->toBe(0)
        ->and($load($duplicate))->toBe(2)
        ->and($load($unknown))->toBe(2)
        ->and($load($stranger))->toBe(2)
        ->and($load(array_merge($protocol, ['version' => 2])))->toBe(2);
});

it('identities: a changed problem status and a removed event payload field are BREAK; a new payload key and a changed event sink are REVIEW (case 5)', function (): void {
    $before = bc_identities('protocol');
    $problem = bc_item($before, 'problem:actor_required');
    $event = bc_item($before, 'event:access.inheritance_changed');
    $variant = static function (callable $edit) use ($before): array {
        $after = $before;
        $edit($after);

        return bc_identity_rows('protocol', $before, $after);
    };

    expect($variant(static function (array &$d) use ($problem): void {
        $d['items'][$problem]['status'] = 403;
    }))->toBe(['BREAK problem:actor_required.status problem-status'])
        ->and($variant(static function (array &$d) use ($event): void {
            $d['items'][$event]['data'] = [];
        }))->toBe(['BREAK event:access.inheritance_changed.data.inherits payload-field-removed'])
        ->and($variant(static function (array &$d) use ($event): void {
            $d['items'][$event]['data']['extra'] = 'string';
        }))->toBe(['REVIEW event:access.inheritance_changed.data.extra payload-field-added'])
        ->and($variant(static function (array &$d) use ($event): void {
            $d['items'][$event]['sinks']['external'] = false;
        }))->toBe(['REVIEW event:access.inheritance_changed.sinks event-sinks'])
        ->and($variant(static function (array &$d) use ($event): void {
            array_splice($d['items'], $event, 1);
        }))->toBe(['BREAK event:access.inheritance_changed identity-removed']);
});

it('identities: a removed auth middleware is a security BREAK, a config env inside a default is not lost in a string, CLI exit codes are REVIEW', function (): void {
    $http = bc_identities('http');
    $route = bc_item($http, 'route:vaulter.v1.access.inheritance.restore');
    $open = $http;
    $open['items'][$route]['middleware'] = array_values(array_diff($http['items'][$route]['middleware'], ['auth:sanctum']));
    $config = bc_identities('config');
    $key = bc_item($config, 'config:vaulter-azguard.create_permission');
    $renamed = $config;
    $renamed['items'][$key]['default']['env'] = 'VAULTER_AZGUARD_OTHER';
    $cli = bc_identities('cli');
    $codes = $cli;
    $codes['items'][0]['exit_codes'][] = 'INVALID';

    expect(bc_identity_rows('http', $http, $open))->toBe(['BREAK route:vaulter.v1.access.inheritance.restore.middleware.auth:sanctum security-middleware-removed'])
        ->and(bc_identity_rows('config', $config, $renamed))->toBe(['REVIEW config:vaulter-azguard.create_permission.default config-default'])
        ->and(bc_identity_rows('cli', $cli, $codes))->toBe(["REVIEW {$cli['items'][0]['id']}.exit_codes cli-exit-codes"]);
});

// --- manifest and source reader ------------------------------------------------------------------------------------------

it('manifest: a lost snapshot, an empty required glob, a surface dropped from the head manifest, a duplicated id, corrupted JSON, an unknown adapter or an unsafe path is exit 2 (case 6)', function (): void {
    $root = bc_scratch(['packages/core/surface.json' => '{}']);
    $reader = SourceReader::worktree($root);
    $collect = static fn (string $json): int => bc_exit(static function () use ($json, $reader): array {
        foreach (SnapshotManifest::parse($json)['surfaces'] as $surface) {
            SnapshotManifest::collect($surface, $reader);
        }

        return [];
    });
    $two = [
        ['id' => 'php:core', 'adapter' => 'php-surface', 'schema_version' => 1, 'owner' => 'core', 'release_line' => 'php-public', 'inputs' => ['packages/core/surface.json'], 'required' => true],
        ['id' => 'php:core', 'adapter' => 'php-surface', 'schema_version' => 1, 'owner' => 'core', 'release_line' => 'php-public', 'inputs' => ['packages/core/other.json'], 'required' => true],
    ];
    $base = SnapshotManifest::parse(bc_manifest(surfaces: [$two[0], ['id' => 'identities:http', 'adapter' => 'identities', 'schema_version' => 1, 'owner' => '*', 'release_line' => '*', 'inputs' => ['packages/core/surface.json'], 'required' => true]]));

    expect($collect(bc_manifest()))->toBe(0)
        ->and($collect(bc_manifest(['inputs' => ['packages/core/gone.json']])))->toBe(2)
        ->and($collect(bc_manifest(['inputs' => ['packages/*/missing-*.json']])))->toBe(2)
        ->and($collect(bc_manifest(surfaces: $two)))->toBe(2)
        ->and($collect('{"format": "vaulter.contract-snapshots", '))->toBe(2)
        ->and($collect(bc_manifest(['adapter' => 'roave'])))->toBe(2)
        ->and($collect(bc_manifest(['schema_version' => 2])))->toBe(2)
        ->and($collect(bc_manifest(['owner' => 'stranger'])))->toBe(2)
        ->and($collect(bc_manifest(['inputs' => ['../outside.json']])))->toBe(2)
        ->and($collect(bc_manifest(['inputs' => ['/etc/passwd']])))->toBe(2)
        ->and($collect(bc_manifest(['inputs' => ['--output=x']])))->toBe(2)
        ->and(bc_exit(static fn (): array => SnapshotManifest::union($base, SnapshotManifest::parse(bc_manifest()))))->toBe(2)
        ->and(bc_exit(static fn (): array => PhpSurface::load(['packages/core/surface.json' => '{"format":'])))->toBe(2);
});

it('manifest: the source reader reads a verified commit as data and refuses a short or unknown SHA, a symlink and an escape', function (): void {
    $root = bc_scratch(['packages/core/surface.json' => "v1\n", 'outside.txt' => 'secret']);
    $git = static function (string ...$arguments) use ($root): string {
        exec('git -C '.escapeshellarg($root).' '.implode(' ', array_map('escapeshellarg', $arguments)).' 2>&1', $output, $exit);
        expect($exit)->toBe(0, implode("\n", $output));

        return trim(implode("\n", $output));
    };
    $git('init', '-q');
    $git('add', '.');
    $git('-c', 'user.name=bc', '-c', 'user.email=bc@example.invalid', 'commit', '-q', '-m', 'base');
    $sha = $git('rev-parse', 'HEAD');
    file_put_contents("{$root}/packages/core/surface.json", "v2\n");
    symlink("{$root}/outside.txt", "{$root}/packages/core/link.json");

    expect(SourceReader::revision($root, $sha)->read('packages/core/surface.json'))->toBe("v1\n")
        ->and(SourceReader::worktree($root)->read('packages/core/surface.json'))->toBe("v2\n")
        ->and(SourceReader::revision($root, $sha)->read('packages/core/absent.json'))->toBeNull()
        ->and(SourceReader::revision($root, $sha)->glob('packages/*/surface.json'))->toBe(['packages/core/surface.json'])
        ->and(fn () => SourceReader::worktree($root)->read('packages/core/link.json'))->toThrow(ContractError::class)
        ->and(fn () => SourceReader::revision($root, substr($sha, 0, 12)))->toThrow(ContractError::class)
        ->and(fn () => SourceReader::revision($root, str_repeat('0', 40)))->toThrow(ContractError::class)
        ->and(fn () => SourceReader::worktree($root)->read('packages/../outside.txt'))->toThrow(ContractError::class);
});

it('manifest: the committed contract-snapshots manifest names every surface with present inputs, each adapter reads them with no change against itself, and the server engines stay pending', function (): void {
    $root = bc_root();
    $manifest = SnapshotManifest::parse((string) file_get_contents($root.'/'.SnapshotManifest::PATH));
    $reader = SourceReader::worktree($root);
    $php = [];
    foreach ($manifest['surfaces'] as $surface) {
        if ($surface['adapter'] === 'php-surface') {
            $php += SnapshotManifest::collect($surface, $reader);
        }
    }
    $types = PhpSurface::load($php);
    $counts = [];
    foreach (SnapshotManifest::union($manifest, $manifest) as $id => $surface) {
        $files = SnapshotManifest::collect($surface, $reader);
        $changes = [];
        foreach (in_array($surface['adapter'], ['php-surface', 'npm-surface'], true) ? [] : $files as $path => $json) {
            $changes = [...$changes, ...($surface['adapter'] === 'identities'
                ? Identities::compare($id, $loaded = Identities::load($json, basename($path, '.json'), bc_owners(), $path), $loaded)
                : SchemaDdl::compare($id, $loaded = SchemaDdl::load($json, $path), $loaded))];
        }
        if ($surface['adapter'] === 'php-surface') {
            $changes = PhpSurface::compare($id, $types, $types, PhpSurface::declared($files));
        }
        if ($surface['adapter'] === 'npm-surface') {
            $report = array_values(array_filter(array_keys($files), static fn (string $p): bool => str_ends_with($p, '.api.md')))[0];
            $changes = NpmSurface::compare($id, $loaded = NpmSurface::load($files[$report], $files[dirname($report).'/components.surface.txt'], $report), $loaded);
        }
        $counts[$id] = [count($files), $changes];
    }

    expect(array_keys($counts))->toContain('php:core', 'identities:protocol', 'identities:schema', 'schema:sqlite', 'schema:pgsql', 'npm:vue')
        ->and(array_filter($counts, static fn (array $c): bool => $c[0] === 0 || $c[1] !== []))->toBe([])
        ->and($manifest['surfaces']['schema:sqlite']['status'])->toBe('current')
        ->and($manifest['surfaces']['schema:pgsql']['status'])->toBe('pending')
        ->and($manifest['surfaces']['schema:mysql']['status'])->toBe('pending');
});

// --- schema ----------------------------------------------------------------------------------------------------------------

it('schema: JSON order is no change; a new NOT NULL column, a nullable column made NOT NULL, a new default or a dropped foreign key is REVIEW; a removed column or table is BREAK; a new table is ADD (case 7)', function (): void {
    $before = bc_ddl();
    $variant = static function (callable $edit) use ($before): array {
        $after = $before;
        $edit($after);

        return bc_ddl_rows($before, $after);
    };
    $column = static fn (array $d, string $name): int => array_search($name, array_column($d['tables']['v_activity']['columns'], 'name'), true);
    $at = 'sqlite:bigint.string.v.off:';

    expect($variant(static function (array &$d): void {
        $d['tables'] = array_reverse($d['tables'], true);
        $d['tables']['v_activity']['columns'] = array_reverse($d['tables']['v_activity']['columns']);
        $d['tables']['v_activity']['create_sql'] = 'reprinted';
    }))->toBe([])
        ->and($variant(static function (array &$d): void {
            $d['tables']['v_activity']['columns'][] = ['name' => 'kind', 'type' => 'varchar', 'notnull' => 1, 'default' => null, 'pk' => 0, 'hidden' => 0];
        }))
        ->toBe(["REVIEW {$at}v_activity.kind column-added-required"])
        ->and($variant(static function (array &$d) use ($column): void {
            $d['tables']['v_activity']['columns'][$column($d, 'actor_id')]['notnull'] = 1;
        }))
        ->toBe(["REVIEW {$at}v_activity.actor_id column-not-null"])
        ->and($variant(static function (array &$d) use ($column): void {
            $d['tables']['v_activity']['columns'][$column($d, 'verb')]['default'] = "'x'";
        }))
        ->toBe(["REVIEW {$at}v_activity.verb column-changed"])
        ->and($variant(static function (array &$d): void {
            $d['tables']['v_blob_references']['foreign_keys'] = [];
        }))
        ->toBe(["REVIEW {$at}v_blob_references#blob_id->v_blobs.id foreign-key-removed"])
        ->and($variant(static function (array &$d) use ($column): void {
            array_splice($d['tables']['v_activity']['columns'], $column($d, 'actor_id'), 1);
        }))
        ->toBe(["BREAK {$at}v_activity.actor_id column-removed"])
        ->and($variant(static function (array &$d): void {
            unset($d['tables']['v_activity']);
        }))
        ->toBe(["BREAK {$at}v_activity table-removed"])
        ->and($variant(static function (array &$d): void {
            $d['tables']['v_new'] = ['columns' => [['name' => 'id', 'type' => 'INTEGER', 'notnull' => 1, 'default' => null, 'pk' => 1, 'hidden' => 0]], 'indexes' => [], 'foreign_keys' => []];
        }))
        ->toBe(["ADD {$at}v_new table-added"]);
});

it('schema: the schema identities compare columns by id, a removed column is BREAK and nullable to NOT NULL is REVIEW; snapshots of another preset or a duplicate column are exit 2 (case 7)', function (): void {
    $before = bc_identities('schema');
    $column = bc_item($before, 'column:v_activity.actor_id');
    $tightened = $before;
    $tightened['items'][$column]['engines']['sqlite']['nullable'] = false;
    $removed = $before;
    array_splice($removed['items'], $column, 1);
    $ddl = bc_ddl();
    $other = array_merge($ddl, ['preset' => 'bigint.string.v.on']);
    $duplicate = $ddl;
    $duplicate['tables']['v_activity']['columns'][] = $ddl['tables']['v_activity']['columns'][0];

    expect(bc_identity_rows('schema', $before, $tightened))->toBe(['REVIEW column:v_activity.actor_id.engines.sqlite column-not-null'])
        ->and(bc_identity_rows('schema', $before, $removed))->toBe(['BREAK column:v_activity.actor_id identity-removed'])
        ->and(Change::exitOf(static fn (): array => SchemaDdl::compare('schema:sqlite', SchemaDdl::load(json_encode($ddl), 'a'), SchemaDdl::load(json_encode($other), 'b'))))->toBe(2)
        ->and(bc_exit(static fn (): array => SchemaDdl::load(json_encode($duplicate), 'dup')))->toBe(2);
});

/** @return array{report: string, components: string} the committed declaration reports of an npm package */
function bc_npm_files(string $package = 'vue'): array
{
    $dir = bc_root()."/packages/{$package}/etc";

    return ['report' => (string) file_get_contents("{$dir}/index.api.md"), 'components' => (string) file_get_contents("{$dir}/components.surface.txt")];
}

/** @return list<string> "CLASSIFICATION subject rule" of the changes from the committed reports to an edit of them */
function bc_npm_rows(callable $edit, string $package = 'vue'): array
{
    $before = bc_npm_files($package);
    $after = $edit($before);
    expect($after)->not->toBe($before);

    return bc_rows(NpmSurface::compare("npm:{$package}", NpmSurface::load($before['report'], $before['components'], 'before'), NpmSurface::load($after['report'], $after['components'], 'after')));
}

it('npm: the committed declaration reports are no change against themselves, and a removed export is a BREAK (bc-npm, scenario 8)', function (): void {
    $f = bc_npm_files();
    foreach (['vue', 'vue-fixture'] as $package) {
        $surface = NpmSurface::load($f['report'], $f['components'], $package);
        expect(NpmSurface::compare("npm:{$package}", $surface, $surface))->toBe([]);
    }
    expect(bc_npm_rows(static fn (array $f): array => ['report' => str_replace("export function createVaulterClient(options?: VaulterClientOptions): VaulterClient;\n", '', $f['report']), 'components' => $f['components']]))
        ->toBe(['BREAK export:function:createVaulterClient export-removed'])
        ->and(bc_npm_rows(static fn (array $f): array => ['report' => str_replace('export function createVaulterClient(', 'export function createVaulterClient2(', $f['report']), 'components' => $f['components']]))
        ->toContain('BREAK export:function:createVaulterClient export-removed')
        ->toContain('ADD export:function:createVaulterClient2 export-added');
});

it('npm: a component prop turned required, a changed prop type or event payload are REVIEW, a removed prop or event is BREAK, a new optional prop is ADD (bc-npm props/emits)', function (): void {
    $edit = static fn (string $from, string $to): callable => static fn (array $f): array => ['report' => $f['report'], 'components' => str_replace($from, $to, $f['components'])];
    $path = 'VaulterBreadcrumb prop path?: { id: string; name: string; }[] | undefined';

    expect(bc_npm_rows($edit($path, 'VaulterBreadcrumb prop path: { id: string; name: string; }[]')))->toBe(['REVIEW prop:VaulterBreadcrumb.path prop-required'])
        ->and(bc_npm_rows($edit('VaulterBreadcrumb prop rootLabel?: string | undefined', 'VaulterBreadcrumb prop rootLabel?: number | undefined')))->toBe(['REVIEW prop:VaulterBreadcrumb.rootLabel prop-changed'])
        ->and(bc_npm_rows($edit('emit (event: "navigate", nodeId: string | null): void', 'emit (event: "navigate", nodeId: string): void')))->toBe(['REVIEW emit:VaulterBreadcrumb@navigate event-changed'])
        ->and(bc_npm_rows($edit("{$path}\n", '')))->toBe(['BREAK prop:VaulterBreadcrumb.path prop-removed'])
        ->and(bc_npm_rows($edit('VaulterBreadcrumb emit (event: "navigate", nodeId: string | null): void', 'VaulterBreadcrumb emit (event: "navigated", nodeId: string | null): void')))
        ->toBe(['BREAK emit:VaulterBreadcrumb@navigate event-removed', 'ADD emit:VaulterBreadcrumb@navigated event-added'])
        ->and(bc_npm_rows($edit("{$path}\n", "{$path}\nVaulterBreadcrumb prop tone?: string | undefined\n")))->toBe(['ADD prop:VaulterBreadcrumb.tone prop-added'])
        ->and(bc_npm_rows($edit("{$path}\n", "{$path}\nVaulterBreadcrumb prop tone: string\n")))->toBe(['REVIEW prop:VaulterBreadcrumb.tone prop-added-required']);
});

it('npm: a file that is no API Extractor report, an empty report or an unreadable component row is exit 2, never green (bc-npm inputs)', function (): void {
    $f = bc_npm_files();

    expect(bc_exit(static fn (): array => NpmSurface::load('not a report', $f['components'], 'a')))->toBe(2)
        ->and(bc_exit(static fn (): array => NpmSurface::load("## API Report File for \"x\"\n", $f['components'], 'b')))->toBe(2)
        ->and(bc_exit(static fn (): array => NpmSurface::load($f['report'], "VaulterBreadcrumb garbage\n", 'c')))->toBe(2)
        ->and(Change::exitOf(static fn (): array => NpmSurface::compare('npm:vue', NpmSurface::load($f['report'], "x\n", 'd'), NpmSurface::load($f['report'], $f['components'], 'e'))))->toBe(2);
});

// --- Release bases, trust and exceptions (bc-bases, release-identity, cases 9-10) -----------------------
// Temporary git repositories only: no network, no fetch, no tag or checkout of the real repository.

function bc_git(string $dir, array $args): string
{
    $process = proc_open(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false', '-c', 'init.defaultBranch=main', '-C', $dir, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($process);

    return trim($out);
}

/**
 * main = A → B → C. Public v1.9.0=A, v1.10.0=B, annotated v1.11.0=C, v1.99.0=C, prerelease v1.12.0-rc.1=C; private addon-v2.0.0=A.
 * C removes Port::read, which A and B have.
 *
 * @return array{dir: string, A: string, B: string, C: string}
 */
function bc_release_repo(bool $removeInC = true): array
{
    $dir = bc_scratch([]);
    bc_git($dir, ['init', '-q']);
    $commit = static function (string $code) use ($dir): string {
        $files = ['packages/core/surface.json' => Surface::structures(Surface::fromSources(bc_src($code)))['core'], SnapshotManifest::PATH => bc_manifest(), 'release/bc-exceptions.json' => bc_exceptions_json()];
        foreach ($files as $path => $bytes) {
            @mkdir(dirname("{$dir}/{$path}"), 0777, true);
            file_put_contents("{$dir}/{$path}", $bytes);
        }
        bc_git($dir, ['add', '-A']);
        bc_git($dir, ['commit', '-q', '--allow-empty', '-m', 'c'.bin2hex(random_bytes(3))]);

        return bc_git($dir, ['rev-parse', 'HEAD']);
    };
    $with = '#[Api] final class Port { public function read(): void {} }';
    $a = $commit($with);
    $b = $commit($with.' ');
    $c = $commit($removeInC ? '#[Api] final class Port {}' : $with);
    foreach ([['v1.9.0', $a], ['v1.10.0', $b], ['v1.99.0', $c], ['v1.12.0-rc.1', $c], ['addon-v2.0.0', $a]] as [$tag, $sha]) {
        bc_git($dir, ['tag', $tag, $sha]);
    }
    bc_git($dir, ['tag', '-a', 'v1.11.0', '-m', 'release', $c]);
    bc_git($dir, ['tag', 'v1.0.0-tree', bc_git($dir, ['rev-parse', 'HEAD^{tree}'])]);

    return ['dir' => $dir, 'A' => $a, 'B' => $b, 'C' => $c];
}

function bc_exceptions_json(array $exceptions = []): string
{
    return json_encode(['format' => 'vaulter.bc-exceptions', 'version' => 1, 'exceptions' => $exceptions], JSON_THROW_ON_ERROR);
}

/** @param list<array<string, mixed>> $known @param list<array<string, mixed>> $approvals */
function bc_policy(array $known = [], array $approvals = [], array $lines = ['php-public' => ['prefix' => 'v', 'major' => 1]]): array
{
    return TrustPolicy::parse(json_encode(['format' => 'vaulter.bc-trust-policy', 'version' => 1, 'lines' => $lines, 'known_releases' => $known, 'decisions' => ['PLAN4-D18'], 'approvals' => $approvals], JSON_THROW_ON_ERROR));
}

const BC_T = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

it('release line base: the candidate is excluded under any tag alias, prerelease and tree tags are no base, and SemVer order beats string order (bc-bases)', function (): void {
    $r = bc_release_repo();
    $base = static fn (string $candidate, string $prefix = 'v', int $major = 1, array $known = []): ?string => ReleaseBases::resolve($r['dir'], $candidate, $prefix, $major, $known)['tag'] ?? null;

    expect($base($r['C']))->toBe('v1.10.0')
        ->and($base($r['B']))->toBe('v1.9.0')
        ->and($base($r['A']))->toBeNull()
        ->and($base($r['C'], 'addon-v', 2))->toBe('addon-v2.0.0')
        ->and(ReleaseBases::semver('1.2.3-rc.1+b.5'))->toBe(['major' => 1, 'minor' => 2, 'patch' => 3, 'pre' => 'rc.1', 'build' => 'b.5'])
        ->and(ReleaseBases::semver('1.2'))->toBeNull()
        ->and(ReleaseBases::semver('01.2.3'))->toBeNull();
});

it('release line base: build-metadata ties, a deleted known tag, a shallow clone and a non-commit candidate are exit 2 (bc-bases, missing tag)', function (): void {
    $r = bc_release_repo();
    $known = [['line' => 'php-public', 'tag' => 'v1.10.0', 'sha' => $r['B']]];
    $resolve = static fn (string $dir, array $known = []): mixed => ReleaseBases::resolve($dir, $r['C'], 'v', 1, $known);
    expect($resolve($r['dir'], $known)['tag'])->toBe('v1.10.0');

    bc_git($r['dir'], ['tag', 'v1.10.0+build.2', $r['B']]);
    expect(bc_exit(static fn (): mixed => $resolve($r['dir'])))->toBe(2);
    bc_git($r['dir'], ['tag', '-d', 'v1.10.0+build.2']);
    bc_git($r['dir'], ['tag', '-d', 'v1.10.0']);
    expect(bc_exit(static fn (): mixed => $resolve($r['dir'])))->toBe(0)
        ->and(bc_exit(static fn (): mixed => $resolve($r['dir'], $known)))->toBe(2)
        ->and(bc_exit(static fn (): mixed => ReleaseBases::resolve($r['dir'], bc_git($r['dir'], ['rev-parse', 'HEAD^{tree}']), 'v', 1, [])))->toBe(2);

    $shallow = bc_scratch([]);
    bc_git($shallow, ['clone', '-q', '--depth=1', 'file://'.$r['dir'], '.']);
    expect(bc_exit(static fn (): mixed => ReleaseBases::resolve($shallow, bc_git($shallow, ['rev-parse', 'HEAD']), 'v', 1, [])))->toBe(2);
});

it('release line base: a removed Port::read between the release base and the candidate is a BREAK on its line; a corrupt seed or manifest is exit 2 (cases 9-10)', function (): void {
    $r = bc_release_repo();
    $run = static fn (array $policy, ?string $exceptions = null, ?string $candidate = null): array => ReleaseCheck::run($r['dir'], $candidate ?? $r['C'], BC_T, $policy, $exceptions ?? bc_exceptions_json(), 'tool', '2026-10-28');

    $result = $run(bc_policy());
    expect($result['exit'])->toBe(1)
        ->and($result['lines']['php-public'])->toBe(['admission' => 'compared', 'base' => 'v1.10.0'])
        ->and(array_map(static fn (array $c): string => "{$c['classification']} {$c['subject']} {$c['rule']} {$c['release_line']}", $result['changes']))->toBe(['BREAK Vaulter\Port::read() member-removed php-public'])
        ->and($run(bc_policy(), null, $r['B'])['exit'])->toBe(0)
        ->and($run(bc_policy(), '{"corrupt'))->toHaveKey('exit', 2)
        ->and($run(bc_policy())['exit'])->toBe(1);

    $broken = bc_release_repo(false);
    file_put_contents($broken['dir'].'/release/contract-snapshots.json', '{"corrupt');
    bc_git($broken['dir'], ['commit', '-qam', 'break manifest']);
    expect(ReleaseCheck::run($broken['dir'], bc_git($broken['dir'], ['rev-parse', 'HEAD']), BC_T, bc_policy(), bc_exceptions_json(), 'tool', '2026-10-28')['exit'])->toBe(2);
});

it('trust: no predecessor is initial_bootstrap and never a BC pass, only an external exact approved_initial_release admits it, and a missing known tag is not masked (release-identity)', function (): void {
    $r = bc_release_repo(false);
    $run = static fn (array $policy, string $candidate): array => ReleaseCheck::run($r['dir'], $candidate, BC_T, $policy, bc_exceptions_json(), 'tool', '2026-10-28');
    $approval = static fn (string $sha): array => ['kind' => 'initial_release', 'candidate_sha' => $sha, 'policy_digest' => TrustPolicy::digest(bc_policy()), 'line' => 'php-public'];

    expect($run(bc_policy(), $r['A']))->toMatchArray(['exit' => 1, 'lines' => ['php-public' => ['admission' => 'initial_bootstrap', 'base' => null]]])
        ->and($run(bc_policy([], [$approval($r['A'])]), $r['A'])['lines']['php-public']['admission'])->toBe('approved_initial_release')
        ->and($run(bc_policy([], [$approval($r['A'])]), $r['A'])['exit'])->toBe(0)
        ->and($run(bc_policy([], [$approval($r['B'])]), $r['A'])['exit'])->toBe(1)
        ->and($run(bc_policy([['line' => 'php-public', 'tag' => 'v0.9.0', 'sha' => $r['A']]], [$approval($r['A'])]), $r['A'])['exit'])->toBe(2);
});

it('trust: a policy revision equal to the candidate and candidate-edited exceptions are self-authorizing and exit 2; a malformed policy is rejected', function (): void {
    $r = bc_release_repo();
    $exception = ['surface' => 'php:core', 'subject' => 'Vaulter\Port::read()', 'rule' => 'member-removed', 'classification' => 'BREAK', 'release_line' => 'php-public', 'before_hash' => null, 'after_hash' => null, 'decision' => 'PLAN4-D18', 'reason' => 'x', 'expiry' => '2030-01-01'];
    $self = ReleaseCheck::run($r['dir'], $r['C'], $r['C'], bc_policy(), bc_exceptions_json(), 'tool', '2026-10-28');
    $edited = ReleaseCheck::run($r['dir'], $r['C'], BC_T, bc_policy(), bc_exceptions_json([$exception]), 'tool', '2026-10-28');

    expect($self['exit'])->toBe(2)
        ->and($self['changes'][0]['error'])->toContain('self-authorizing')
        ->and($edited['exit'])->toBe(2)
        ->and($edited['changes'][0]['error'])->toContain('self-authorizing')
        ->and(bc_exit(static fn (): array => TrustPolicy::parse('{"format":"x"}')))->toBe(2)
        ->and(bc_exit(static fn (): array => TrustPolicy::parse('not json')))->toBe(2);
});

it('exception: only an exact, unexpired exception bound by an external approval to the exact candidate, policy, exceptions and tool digests suppresses a change (release-identity waiver)', function (): void {
    $change = Change::make('php:core', 'Vaulter\Port::read()', 'member-removed', Change::BREAK, 'b', null, 'x') + ['release_line' => 'php-public'];
    $exception = ['surface' => 'php:core', 'subject' => 'Vaulter\Port::read()', 'rule' => 'member-removed', 'classification' => 'BREAK', 'release_line' => 'php-public', 'before_hash' => $change['before_hash'], 'after_hash' => null, 'decision' => 'PLAN4-D18', 'reason' => 'approved', 'expiry' => '2026-12-31'];
    $json = bc_exceptions_json([$exception]);
    $policy = bc_policy();
    $digests = ['candidate_sha' => str_repeat('c', 40), 'policy_digest' => TrustPolicy::digest($policy), 'exceptions_digest' => hash('sha256', $json), 'tool_digest' => 'tool'];
    $withApproval = static fn (array $d): array => bc_policy([], [['kind' => 'exceptions'] + $d]);
    $apply = static fn (array $e, array $p, array $d, string $today = '2026-10-28', ?array $c = null): int => count(TrustPolicy::apply([$c ?? $change], $e, $p, $d, $today)['remaining']);

    expect($apply([$exception], $withApproval($digests), $digests))->toBe(0)
        ->and($apply([$exception], $policy, $digests))->toBe(1)
        ->and($apply([$exception], $withApproval($digests), $digests, '2027-01-01'))->toBe(1)
        ->and($apply([$exception], $withApproval($digests), ['candidate_sha' => str_repeat('d', 40)] + $digests))->toBe(1)
        ->and($apply([$exception], $withApproval($digests), ['tool_digest' => 'other'] + $digests))->toBe(1)
        ->and($apply([$exception], $withApproval($digests), $digests, '2026-10-28', ['subject' => 'Vaulter\Port::write()'] + $change))->toBe(1)
        ->and($apply([$exception], $withApproval($digests), $digests, '2026-10-28', ['after_hash' => str_repeat('1', 64)] + $change))->toBe(1)
        ->and($apply([$exception], $withApproval($digests), $digests, '2026-10-28', ['release_line' => 'php-addon'] + $change))->toBe(1)
        ->and(bc_exit(static fn (): array => TrustPolicy::exceptions(bc_exceptions_json([['subject' => 'Vaulter\*'] + $exception]), $policy)))->toBe(2)
        ->and(bc_exit(static fn (): array => TrustPolicy::exceptions(bc_exceptions_json([['decision' => 'PLAN4-D99'] + $exception]), $policy)))->toBe(2)
        ->and(count(TrustPolicy::exceptions($json, $policy)))->toBe(1);
});

it('exception: the CLI runs from the tool checkout, reads the candidate as data and exits 1 on the removed method, 2 without the trusted inputs (cases 9-10)', function (): void {
    $r = bc_release_repo();
    $files = bc_scratch(['policy.json' => json_encode(['format' => 'vaulter.bc-trust-policy', 'version' => 1, 'lines' => ['php-public' => ['prefix' => 'v', 'major' => 1]], 'known_releases' => [], 'decisions' => ['PLAN4-D18'], 'approvals' => []]), 'exceptions.json' => bc_exceptions_json()]);
    $cli = static function (array $args): int {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(bc_root().'/scripts/release/bc-check.php').' '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1', $output, $exit);

        return $exit;
    };
    $args = ["--root={$r['dir']}", "--candidate={$r['C']}", "--policy={$files}/policy.json", '--policy-rev='.BC_T, "--exceptions={$files}/exceptions.json", '--today=2026-10-28'];

    expect($cli($args))->toBe(1)
        ->and($cli(["--root={$r['dir']}", "--candidate={$r['B']}", ...array_slice($args, 2)]))->toBe(0)
        ->and($cli(array_slice($args, 0, 3)))->toBe(2)
        ->and(strlen(ReleaseCheck::toolDigest(bc_root().'/scripts/release')))->toBe(64);
});

// --- Stage C: npm dispatch end to end and the full cross-line PHP ancestor context (review_release; Opus F1/F2, Grok F1) --------

/**
 * A scratch repo with one full-snapshot commit per entry (an unchanged snapshot is still a commit) and the given tags.
 *
 * @param  list<array<string, string>>            $commits path => bytes
 * @param  array<string, int>                     $tags    tag => commit index
 * @return array{dir: string, shas: list<string>}
 */
function bc_review_repo(array $commits, array $tags): array
{
    $dir = bc_scratch([]);
    bc_git($dir, ['init', '-q']);
    $shas = [];
    foreach ($commits as $files) {
        foreach (array_filter(explode("\n", bc_git($dir, ['ls-files']))) as $tracked) {
            unlink("{$dir}/{$tracked}");
        }
        foreach ($files as $path => $bytes) {
            @mkdir(dirname("{$dir}/{$path}"), 0777, true);
            file_put_contents("{$dir}/{$path}", $bytes);
        }
        bc_git($dir, ['add', '-A']);
        bc_git($dir, ['commit', '-q', '--allow-empty', '-m', 'c'.bin2hex(random_bytes(3))]);
        $shas[] = bc_git($dir, ['rev-parse', 'HEAD']);
    }
    foreach ($tags as $tag => $index) {
        bc_git($dir, ['tag', $tag, $shas[$index]]);
    }

    return ['dir' => $dir, 'shas' => $shas];
}

/**
 * @param  array{dir: string, shas: list<string>} $repo
 * @return array<string, mixed>                   the whole coordinator against a candidate of the repo
 */
function bc_review_run(array $repo, int $candidate, array $policy): array
{
    return ReleaseCheck::run($repo['dir'], $repo['shas'][$candidate], BC_T, $policy, bc_exceptions_json(), 'tool', '2026-10-28');
}

/** @return list<string> */
function bc_review_rows(array $result): array
{
    return array_map(static fn (array $c): string => "{$c['classification']} {$c['subject']} {$c['rule']} {$c['release_line']}", $result['changes']);
}

/** @param list<array<string, mixed>> $surfaces */
function bc_review_manifest_json(array $lines, array $surfaces): string
{
    return json_encode(['format' => 'vaulter.contract-snapshots', 'version' => 1, 'release_lines' => $lines, 'surfaces' => $surfaces], JSON_THROW_ON_ERROR);
}

function bc_review_npm_surface(string $package, string $status = 'current'): array
{
    return ['id' => "npm:{$package}", 'adapter' => 'npm-surface', 'schema_version' => 1, 'owner' => $package, 'release_line' => "npm-{$package}", 'inputs' => ["packages/{$package}/etc/index.api.md", "packages/{$package}/etc/components.surface.txt"], 'required' => true, 'status' => $status];
}

/** @return array<string, string> the npm files of both test libraries (using the committed Vue report) plus the manifest */
function bc_review_npm_files(string $manifest, array $edit = []): array
{
    $files = [SnapshotManifest::PATH => $manifest];
    $f = bc_npm_files();
    foreach (['vue', 'vue-fixture'] as $package) {
        $files["packages/{$package}/etc/index.api.md"] = $f['report'];
        $files["packages/{$package}/etc/components.surface.txt"] = $f['components'];
    }

    return array_replace($files, $edit);
}

function bc_review_npm_policy(): array
{
    return bc_policy([], [], ['npm-vue' => ['prefix' => '@vaulter/vue@', 'major' => 1], 'npm-vue-fixture' => ['prefix' => '@vaulter/vue-fixture@', 'major' => 0]]);
}

it('review_release: an npm export removed is a BREAK on its own line, a prop turned required and an event change are REVIEW, and the unchanged second npm line stays empty end to end', function (): void {
    $manifest = bc_review_manifest_json(['npm-vue' => ['owners' => ['vue']], 'npm-vue-fixture' => ['owners' => ['vue-fixture']]], [bc_review_npm_surface('vue'), bc_review_npm_surface('vue-fixture')]);
    $base = bc_review_npm_files($manifest);
    $vue = bc_npm_files('vue');
    $fixture = bc_npm_files();
    $repo = bc_review_repo([
        $base,
        bc_review_npm_files($manifest, ['packages/vue/etc/index.api.md' => str_replace("export function createVaulterClient(options?: VaulterClientOptions): VaulterClient;\n", '', $vue['report'])]),
        bc_review_npm_files($manifest, ['packages/vue/etc/components.surface.txt' => str_replace('prop path?: { id: string; name: string; }[] | undefined', 'prop path: { id: string; name: string; }[]', $vue['components'])]),
        bc_review_npm_files($manifest, ['packages/vue/etc/components.surface.txt' => str_replace('emit (event: "navigate", nodeId: string | null): void', 'emit (event: "navigate", nodeId: string): void', $vue['components'])]),
        bc_review_npm_files($manifest, ['packages/vue-fixture/etc/index.api.md' => str_replace('createVaulterClient', 'createVaulterClient2', $fixture['report'])]),
        $base,
    ], ['@vaulter/vue@1.0.0' => 0, '@vaulter/vue-fixture@0.1.0' => 0, '@vaulter/vue@1.1.0-rc.1' => 5]);
    $policy = bc_review_npm_policy();

    $removed = bc_review_run($repo, 1, $policy);
    expect($removed['exit'])->toBe(1)
        ->and($removed['lines'])->toBe(['npm-vue' => ['admission' => 'compared', 'base' => '@vaulter/vue@1.0.0'], 'npm-vue-fixture' => ['admission' => 'compared', 'base' => '@vaulter/vue-fixture@0.1.0']])
        ->and(bc_review_rows($removed))->toBe(['BREAK export:function:createVaulterClient export-removed npm-vue'])
        ->and(bc_review_rows(bc_review_run($repo, 2, $policy)))->toBe(['REVIEW prop:VaulterBreadcrumb.path prop-required npm-vue'])
        ->and(bc_review_rows(bc_review_run($repo, 3, $policy)))->toBe(['REVIEW emit:VaulterBreadcrumb@navigate event-changed npm-vue'])
        ->and(bc_review_rows(bc_review_run($repo, 4, $policy)))->toBe(['BREAK export:function:createVaulterClient export-removed npm-vue-fixture', 'ADD export:function:createVaulterClient2 export-added npm-vue-fixture'])
        ->and(bc_review_run($repo, 4, $policy)['exit'])->toBe(1)
        ->and(bc_review_run($repo, 5, $policy))->toMatchArray(['exit' => 0, 'changes' => []]);
});

it('review_release: a malformed or missing npm report or components snapshot, an unknown npm shape, a downgraded npm surface and a release line missing from the manifest are tooling errors, never green', function (): void {
    $lines = ['npm-vue' => ['owners' => ['vue']], 'npm-vue-fixture' => ['owners' => ['vue-fixture']]];
    $manifest = bc_review_manifest_json($lines, [bc_review_npm_surface('vue'), bc_review_npm_surface('vue-fixture')]);
    $pending = bc_review_manifest_json($lines, [bc_review_npm_surface('vue', 'pending'), bc_review_npm_surface('vue-fixture')]);
    $oneLine = bc_review_manifest_json(['npm-vue' => ['owners' => ['vue']]], [bc_review_npm_surface('vue')]);
    $shape = bc_review_manifest_json($lines, [['inputs' => ['packages/vue/etc/index.api.md', 'packages/vue/etc/index.api.md.bak']] + bc_review_npm_surface('vue'), bc_review_npm_surface('vue-fixture')]);
    $variants = [
        'malformed report' => bc_review_npm_files($manifest, ['packages/vue/etc/index.api.md' => 'not a report']),
        'garbage component row' => bc_review_npm_files($manifest, ['packages/vue/etc/components.surface.txt' => "VaulterBreadcrumb garbage\n"]),
        'missing components file' => array_diff_key(bc_review_npm_files($manifest), ['packages/vue/etc/components.surface.txt' => 1]),
        'missing report' => array_diff_key(bc_review_npm_files($manifest), ['packages/vue-fixture/etc/index.api.md' => 1]),
        'downgraded to pending' => bc_review_npm_files($pending),
        'line missing from the manifest' => bc_review_npm_files($oneLine),
        'unknown npm shape' => bc_review_npm_files($shape, ['packages/vue/etc/index.api.md.bak' => 'x']),
    ];
    $repo = bc_review_repo([bc_review_npm_files($manifest), ...array_values($variants)], ['@vaulter/vue@1.0.0' => 0, '@vaulter/vue-fixture@0.1.0' => 0]);
    $policy = bc_review_npm_policy();

    foreach (array_keys($variants) as $i => $name) {
        $result = bc_review_run($repo, $i + 1, $policy);
        expect($result['exit'])->toBe(2, $name)->and($result['changes'][0])->toHaveKey('error');
    }
});

it('review_release: a byte-identical second PHP line has zero ancestor-unresolved noise because the PHP graph spans every line, while a removed core method is still a BREAK on its own line', function (): void {
    $sources = static fn (string $coreBody): array => [
        'packages/core/src/Base.php' => "<?php\nnamespace Vaulter;\nuse Packman\\Attributes\\Api;\n#[Api] abstract class Base { {$coreBody} }",
        'packages/documents/src/Child.php' => "<?php\nnamespace Vaulter\\Documents;\nuse Packman\\Attributes\\Api;\nuse Vaulter\\Base;\n#[Api] final class Child extends Base {}",
    ];
    $lines = ['php-public' => ['owners' => ['core']], 'php-addon' => ['owners' => ['documents']]];
    $php = static fn (string $package, string $line): array => ['id' => "php:{$package}", 'adapter' => 'php-surface', 'schema_version' => 1, 'owner' => $package, 'release_line' => $line, 'inputs' => ["packages/{$package}/surface.json"], 'required' => true];
    $manifest = bc_review_manifest_json($lines, [$php('documents', 'php-addon'), $php('core', 'php-public')]);
    $files = static function (string $coreBody) use ($sources, $manifest): array {
        $structures = Surface::structures(Surface::fromSources($sources($coreBody)));

        return [SnapshotManifest::PATH => $manifest, 'packages/core/surface.json' => $structures['core'], 'packages/documents/surface.json' => $structures['documents']];
    };
    $repo = bc_review_repo([$files('public function read(): void {}'), $files('public function read(): void {}'), $files('')], ['v1.0.0' => 0, 'addon-v1.0.0' => 0]);
    $policy = bc_policy([], [], ['php-public' => ['prefix' => 'v', 'major' => 1], 'php-addon' => ['prefix' => 'addon-v', 'major' => 1]]);

    $removed = bc_review_run($repo, 2, $policy);
    expect(bc_review_run($repo, 1, $policy))->toMatchArray(['exit' => 0, 'changes' => []])
        ->and(bc_review_rows($removed))->toContain('BREAK Vaulter\Base::read() member-removed php-public')
        ->and(array_filter(bc_review_rows($removed), static fn (string $r): bool => str_contains($r, 'unresolved')))->toBe([]);
});

it('OpenAPI: the public owner and shared ref closure use the resolved line base, missing refs and unavailable tools fail closed (case 11)', function (): void {
    $r = bc_release_repo(false);
    $spec = "openapi: 3.0.3\npaths: {}\ncomponents:\n  schemas:\n    Shared:\n      \$ref: '../../shared.yaml#/Value'\n";
    mkdir($r['dir'].'/openapi');
    file_put_contents($r['dir'].'/openapi/vaulter-v1.yaml', str_replace('../../shared.yaml', '../shared.yaml', $spec));
    file_put_contents($r['dir'].'/shared.yaml', "Value:\n  type: string\n");
    bc_git($r['dir'], ['add', '.']);
    bc_git($r['dir'], ['commit', '-qm', 'specs']);
    $base = bc_git($r['dir'], ['rev-parse', 'HEAD']);
    bc_git($r['dir'], ['tag', 'spec-base', $base]);
    file_put_contents($r['dir'].'/shared.yaml', "Value:\n  type: integer\n");
    bc_git($r['dir'], ['commit', '-qam', 'shared edit']);
    $candidate = bc_git($r['dir'], ['rev-parse', 'HEAD']);
    $scratch = bc_scratch(['fake' => "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> \"\$BC_ARGV\"\nexit \"\${BC_FAKE_EXIT:-0}\"\n", 'report.json' => json_encode(['exit' => 0, 'lines' => ['php-public' => ['admission' => 'compared', 'base' => 'spec-base']]])]);
    chmod($scratch.'/fake', 0755);
    $run = static function (string $binary, string $exit = '0', bool $trustedParser = false) use ($r, $candidate, $scratch): array {
        $env = ['PATH' => '/usr/bin:/bin', 'BC_PYTHON' => '/usr/bin/python3', 'BC_OASDIFF_BIN' => $binary, 'BC_ARGV' => $scratch.'/argv', 'BC_FAKE_EXIT' => $exit];
        $env['BC_REQUIRE_TOOL_PARSER'] = $trustedParser ? '1' : '0';
        $p = proc_open(['/bin/bash', bc_root().'/scripts/release/oasdiff-check.sh', $r['dir'], $candidate, $scratch.'/report.json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $scratch, $env);
        $report = json_decode(stream_get_contents($pipes[1]), true);
        stream_get_contents($pipes[2]);

        return [proc_close($p), $report];
    };
    [$exit, $report] = $run($scratch.'/fake');
    expect($exit)->toBe(0)
        ->and(array_column($report['openapi'], 'owner'))->toBe(['core'])
        ->and(array_column($report['openapi'], 'base_sha'))->toBe([$base])
        ->and($report['actual_runtime_qualification'])->toBeFalse()
        ->and(substr_count(file_get_contents($scratch.'/argv'), '--fail-on'))->toBe(1)
        ->and($run($scratch.'/fake', '1')[0])->toBe(1)
        ->and($run($scratch.'/fake', '0', true)[0])->toBe(2)
        ->and($run($scratch.'/missing')[0])->toBe(2);
    file_put_contents($r['dir'].'/shared.yaml', "Other: {}\n");
    bc_git($r['dir'], ['commit', '-qam', 'broken ref']);
    $broken = bc_git($r['dir'], ['rev-parse', 'HEAD']);
    exec('BC_PYTHON=/usr/bin/python3 BC_OASDIFF_BIN='.escapeshellarg($scratch.'/fake').' /bin/bash '.escapeshellarg(bc_root().'/scripts/release/oasdiff-check.sh').' '.escapeshellarg($r['dir']).' '.escapeshellarg($broken).' '.escapeshellarg($scratch.'/report.json').' 2>&1', $output, $code);
    expect($code)->toBe(2);
});

it('qualification: a signature guard never grants runtime, serial, archive consumer or upgrade qualification (case 12)', function (): void {
    $r = bc_release_repo(false);
    $report = ReleaseCheck::run($r['dir'], $r['B'], BC_T, bc_policy(), bc_exceptions_json(), 'tool', '2026-10-28');
    expect($report['exit'])->toBe(0)
        ->and($report)->not->toHaveKey('actual_runtime_qualification', true)
        ->and(file_get_contents(bc_root().'/scripts/release/Contract/trusted-host.py'))->toContain("'actual_runtime_qualification': False");
});

// --- review R1 correction: manifest status and adapter kind ---------------------------------------------------------

/** A manifest of one surface with the given status and adapter, on a php and an npm release line. */
function bc_review_manifest(string $status, string $adapter = 'php-surface', int $version = 1): array
{
    return SnapshotManifest::parse(json_encode([
        'format' => 'vaulter.contract-snapshots',
        'version' => 1,
        'release_lines' => ['php-public' => ['owners' => ['core']], 'npm-vue' => ['owners' => ['vue']]],
        'surfaces' => [[
            'id' => 'surface:x', 'adapter' => $adapter, 'schema_version' => $version,
            'owner' => $adapter === 'npm-surface' ? 'vue' : 'core', 'release_line' => $adapter === 'npm-surface' ? 'npm-vue' : 'php-public',
            'inputs' => ['packages/x/surface.json'], 'required' => true, 'status' => $status,
        ]],
    ], JSON_THROW_ON_ERROR));
}

it('review_manifest: a surface current in the base cannot turn pending in the candidate head, so a base BREAK is never hidden; pending to current is allowed', function (): void {
    $current = bc_review_manifest('current');
    $pending = bc_review_manifest('pending');

    expect(bc_exit(static fn (): array => SnapshotManifest::union($current, $pending)))->toBe(2)
        ->and(fn () => SnapshotManifest::union($current, $pending))->toThrow(ContractError::class, 'surface:x: status current in the base cannot become pending')
        ->and(SnapshotManifest::union($pending, $current)['surface:x']['status'])->toBe('current')
        ->and(SnapshotManifest::union($pending, $pending)['surface:x']['status'])->toBe('pending')
        ->and(SnapshotManifest::union($current, $current)['surface:x']['status'])->toBe('current');
});

it('review_manifest: the npm declaration adapter is registered exactly as npm-surface schema 1; another spelling or version stays exit 2', function (): void {
    expect(SnapshotManifest::ADAPTERS)->toHaveKey('npm-surface')
        ->and(SnapshotManifest::ADAPTERS['npm-surface'])->toBe([1])
        ->and(bc_review_manifest('current', 'npm-surface')['surfaces']['surface:x']['adapter'])->toBe('npm-surface')
        ->and(bc_exit(static fn (): array => bc_review_manifest('current', 'npm')))->toBe(2)
        ->and(bc_exit(static fn (): array => bc_review_manifest('current', 'npm-surfaces')))->toBe(2)
        ->and(bc_exit(static fn (): array => bc_review_manifest('current', 'npm-surface', 2)))->toBe(2)
        ->and(bc_exit(static fn (): array => bc_review_manifest('stale')))->toBe(2);
});
