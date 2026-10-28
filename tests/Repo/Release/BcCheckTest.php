<?php

declare(strict_types=1);

use Vaulter\Inventory\InventoryError;
use Vaulter\Inventory\Surface;
use Vaulter\Release\Contract\Change;
use Vaulter\Release\Contract\ContractError;
use Vaulter\Release\Contract\Identities;
use Vaulter\Release\Contract\PhpSurface;
use Vaulter\Release\Contract\SchemaDdl;
use Vaulter\Release\Contract\SnapshotManifest;
use Vaulter\Release\Contract\SourceReader;

/*
 * The structural adapters of the BC check (D14, PLAN4 P6.2/U1; knowledge p6-surface#acceptance cases 1–7): the PHP surface
 * from surface.json, the identities and the schema DDL, each compared by stable key, and the snapshot manifest with its exit-2
 * inputs. The `php:` cases build surface.json with the same reader as the generator (Surface::fromSources); the `identities:`
 * and `schema:` cases mutate the committed generated fixtures. A green adapter proves the signature guard only — no runtime,
 * consumer or upgrade qualification. Release bases, exceptions and the CLI are U3; npm is U2.
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
    return ['packages/core/src/Fixture.php' => "<?php\nnamespace Vaulter;\nuse Vaulter\\Attributes\\Api;\nuse Vaulter\\Attributes\\Spi;\nuse Vaulter\\Attributes\\SpiKind;\nuse Vaulter\\Attributes\\Internal;\n".$code];
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
        ->and(bc_php($code, str_replace("trait Quiet { public function say(): string { return ''; }", "trait Quiet { public function say(): int { return 0; }", $code)))->toBe([])
        ->and(bc_php($code, str_replace("trait Loud { public function say(): string { return ''; }", "trait Loud { public function say(): int { return 0; }", $code)))->toBe(['REVIEW Vaulter\Child::say() return-type'])
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

    expect($variant(static function (array &$d) use ($problem): void { $d['items'][$problem]['status'] = 403; }))->toBe(['BREAK problem:actor_required.status problem-status'])
        ->and($variant(static function (array &$d) use ($event): void { $d['items'][$event]['data'] = []; }))->toBe(['BREAK event:access.inheritance_changed.data.inherits payload-field-removed'])
        ->and($variant(static function (array &$d) use ($event): void { $d['items'][$event]['data']['extra'] = 'string'; }))->toBe(['REVIEW event:access.inheritance_changed.data.extra payload-field-added'])
        ->and($variant(static function (array &$d) use ($event): void { $d['items'][$event]['sinks']['external'] = false; }))->toBe(['REVIEW event:access.inheritance_changed.sinks event-sinks'])
        ->and($variant(static function (array &$d) use ($event): void { array_splice($d['items'], $event, 1); }))->toBe(['BREAK event:access.inheritance_changed identity-removed']);
});

it('identities: a removed auth middleware is a security BREAK, a config env inside a default is not lost in a string, CLI exit codes are REVIEW', function (): void {
    $http = bc_identities('http');
    $route = bc_item($http, 'route:vaulter.v1.access.inheritance.restore');
    $open = $http;
    $open['items'][$route]['middleware'] = array_values(array_diff($http['items'][$route]['middleware'], ['auth:sanctum']));
    $config = bc_identities('config');
    $key = bc_item($config, 'config:vaulter-azgard.create_permission');
    $renamed = $config;
    $renamed['items'][$key]['default']['env'] = 'VAULTER_AZGARD_OTHER';
    $cli = bc_identities('cli');
    $codes = $cli;
    $codes['items'][0]['exit_codes'][] = 'INVALID';

    expect(bc_identity_rows('http', $http, $open))->toBe(['BREAK route:vaulter.v1.access.inheritance.restore.middleware.auth:sanctum security-middleware-removed'])
        ->and(bc_identity_rows('config', $config, $renamed))->toBe(['REVIEW config:vaulter-azgard.create_permission.default config-default'])
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
        foreach ($surface['adapter'] === 'php-surface' ? [] : $files as $path => $json) {
            $changes = [...$changes, ...($surface['adapter'] === 'identities'
                ? Identities::compare($id, $loaded = Identities::load($json, basename($path, '.json'), bc_owners(), $path), $loaded)
                : SchemaDdl::compare($id, $loaded = SchemaDdl::load($json, $path), $loaded))];
        }
        if ($surface['adapter'] === 'php-surface') {
            $changes = PhpSurface::compare($id, $types, $types, PhpSurface::declared($files));
        }
        $counts[$id] = [count($files), $changes];
    }

    expect(array_keys($counts))->toContain('php:core', 'php:collaboration', 'identities:protocol', 'identities:schema', 'schema:sqlite', 'schema:pgsql')
        ->and(array_filter($counts, static fn (array $c): bool => $c[0] === 0 || $c[1] !== []))->toBe([])
        ->and($manifest['surfaces']['php:collaboration']['release_line'])->toBe('php-collaboration')
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
        ->and($variant(static function (array &$d): void { $d['tables']['v_activity']['columns'][] = ['name' => 'kind', 'type' => 'varchar', 'notnull' => 1, 'default' => null, 'pk' => 0, 'hidden' => 0]; }))
        ->toBe(["REVIEW {$at}v_activity.kind column-added-required"])
        ->and($variant(static function (array &$d) use ($column): void { $d['tables']['v_activity']['columns'][$column($d, 'actor_id')]['notnull'] = 1; }))
        ->toBe(["REVIEW {$at}v_activity.actor_id column-not-null"])
        ->and($variant(static function (array &$d) use ($column): void { $d['tables']['v_activity']['columns'][$column($d, 'verb')]['default'] = "'x'"; }))
        ->toBe(["REVIEW {$at}v_activity.verb column-changed"])
        ->and($variant(static function (array &$d): void { $d['tables']['v_blob_references']['foreign_keys'] = []; }))
        ->toBe(["REVIEW {$at}v_blob_references#blob_id->v_blobs.id foreign-key-removed"])
        ->and($variant(static function (array &$d) use ($column): void { array_splice($d['tables']['v_activity']['columns'], $column($d, 'actor_id'), 1); }))
        ->toBe(["BREAK {$at}v_activity.actor_id column-removed"])
        ->and($variant(static function (array &$d): void { unset($d['tables']['v_activity']); }))
        ->toBe(["BREAK {$at}v_activity table-removed"])
        ->and($variant(static function (array &$d): void { $d['tables']['v_new'] = ['columns' => [['name' => 'id', 'type' => 'INTEGER', 'notnull' => 1, 'default' => null, 'pk' => 1, 'hidden' => 0]], 'indexes' => [], 'foreign_keys' => []]; }))
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
