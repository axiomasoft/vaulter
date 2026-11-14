<?php

declare(strict_types=1);

use Vaulter\Inventory\Allowlist;
use Vaulter\Inventory\InventoryError;
use Vaulter\Inventory\Repo;
use Vaulter\Inventory\Rules;
use Vaulter\Inventory\SourceIndex;

/*
 * The inventory harness: SourceIndex reads every kind of reference one way, Allowlist and Rules judge exactly.
 * Synthetic code is passed as strings, never as .php files: Pint would reformat them and drop "unused" imports.
 * Fix a red fixture with: scripts/php84 php scripts/inventory/inventory.php <name> --write
 */

require_once dirname(__DIR__, 3).'/scripts/inventory/bootstrap.php';

const INV_PSR4 = ['Vaulter\\' => 'core', 'Vaulter\\Media\\' => 'media', 'Vaulter\\Drive\\' => 'drive', 'Vaulter\\Collaboration\\' => 'collaboration'];

function inv_root(): string
{
    return dirname(__DIR__, 3);
}

/**
 * @param array<string, string> $sources
 * @param list<string>          $tables
 */
function inv_index(array $sources, array $tables = ['drives', 'nodes']): SourceIndex
{
    return SourceIndex::fromSources($sources, INV_PSR4, $tables);
}

function inv_index_one(string $code, string $path = 'packages/drive/src/X.php'): SourceIndex
{
    return inv_index([$path => $code]);
}

/**
 * @return list<array{string, string}> [symbol, target] of the references of one kind
 */
function inv_refs(SourceIndex $index, string $kind): array
{
    return array_map(static fn (array $r): array => [$r['symbol'], $r['target']], $index->references($kind));
}

/** @return list<string> targets of one kind, in source order */
function inv_targets(SourceIndex $index, string $kind): array
{
    return array_column(inv_refs($index, $kind), 1);
}

/** @return array{0: int, 1: string, 2: string} */
function inv_cli(array $args, ?string $cwd = null): array
{
    $process = proc_open([PHP_BINARY, inv_root().'/scripts/inventory/inventory.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd ?? inv_root());
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    return [proc_close($process), $out, $err];
}

/** A throw-away repository with two packages and a packages fixture. */
function inv_temp_repo(array $fixture): Repo
{
    $root = sys_get_temp_dir().'/inventory-'.bin2hex(random_bytes(6));
    mkdir($root.'/packages/alpha', 0775, true);
    mkdir($root.'/packages/beta', 0775, true);
    mkdir($root.'/tests/Repo/Arch/Fixtures', 0775, true);
    file_put_contents($root.'/packages/alpha/composer.json', json_encode(['name' => 'axioma-studio/vaulter-alpha', 'autoload' => ['psr-4' => ['Vaulter\\Alpha\\' => 'src/']]]));
    file_put_contents($root.'/packages/beta/composer.json', json_encode(['name' => 'axioma-studio/vaulter-beta', 'autoload' => ['psr-4' => ['Vaulter\\Beta\\' => 'src/']], 'require' => ['axioma-studio/vaulter-alpha' => 'self.version', 'php' => '^8.4']]));
    file_put_contents($root.'/'.Repo::PACKAGES_FIXTURE, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Repo::at($root);
}

function inv_remove(string $dir): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}

function inv_generator(): object
{
    return require inv_root().'/scripts/inventory/generators/packages.php';
}

// --- SourceIndex: one test per kind of reference --------------------------------------------------------------

it('reads imports, group use and function/const use as use references', function (): void {
    $index = inv_index_one(<<<'PHP'
        <?php
        namespace Vaulter\Drive;

        use Vaulter\Media\{BlobStore, Cas\Hasher as H};
        use Vaulter\Models\Node;
        use function Vaulter\helper;
        use const Vaulter\LIMIT;
        PHP);

    expect(inv_targets($index, 'use'))->toBe([
        'Vaulter\Media\BlobStore', 'Vaulter\Media\Cas\Hasher', 'Vaulter\Models\Node', 'Vaulter\helper', 'Vaulter\LIMIT',
    ])->and(inv_refs($index, 'use')[0][0])->toBe('<file>');
});

it('resolves relative names, imports and ::class to the same fully qualified name', function (): void {
    $index = inv_index_one(<<<'PHP'
        <?php
        namespace Vaulter\Drive\Actions;

        use Vaulter\Media\BlobStore;
        use Vaulter\Models\Drive as DriveModel;

        final class Run
        {
            public function __invoke(): void
            {
                new Sibling();
                new Sub\Deep();
                new BlobStore();
                DriveModel::query();
                $n = Other::class;
                $s = \Vaulter\Absolute::VALUE;
                new self();
                new static();
                echo PHP_EOL;
                strlen('x');
            }
        }
        PHP);

    expect(inv_targets($index, 'name'))->toBe([
        'Vaulter\Drive\Actions\Sibling', 'Vaulter\Drive\Actions\Sub\Deep', 'Vaulter\Media\BlobStore',
        'Vaulter\Models\Drive', 'Vaulter\Drive\Actions\Other', 'Vaulter\Absolute',
    ]);
});

it('reads union, nullable, intersection types, attributes, catch, instanceof and static members as names', function (): void {
    $index = inv_index_one(<<<'PHP'
        <?php
        namespace Vaulter\Drive;

        use Vaulter\Media\{A, B, C, D, E, F, G};

        #[G]
        final class Run extends A implements B
        {
            use C;

            private ?D $d = null;

            public function go(E|F|null $x, B&C $y): F
            {
                try {
                    return $x instanceof A ? $x : Z::make();
                } catch (A|D $e) {
                    return Y::$prop;
                }
            }
        }
        PHP);

    $names = inv_targets($index, 'name');
    foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $short) {
        expect($names)->toContain('Vaulter\Media\\'.$short);
    }
    expect($names)->toContain('Vaulter\Drive\Z')->toContain('Vaulter\Drive\Y')
        ->and(array_count_values($names)['Vaulter\Media\A'])->toBe(3)
        ->and(array_count_values($names)['Vaulter\Media\F'])->toBe(2);
});

it('reads a Vaulter FQCN string with and without a leading backslash and nothing else', function (): void {
    $index = inv_index_one(<<<'PHP'
        <?php
        return [
            'a' => 'Vaulter\\Drive\\Node',
            'b' => '\\Vaulter\\Media\\Blob',
            'c' => 'Vaulter',
            'd' => 'App\\Vaulter\\Node',
            'e' => 'vaulter\\drive',
            'f' => 'Vaulter\\Drive\\Node and more',
        ];
        PHP, 'packages/drive/config/x.php');

    expect(inv_refs($index, 'fqcn-string'))->toBe([['<file>', 'Vaulter\Drive\Node'], ['<file>', 'Vaulter\Media\Blob']]);
});

it('reads table names from table calls, Schema, $table, named arguments and table-literal in Schema classes', function (): void {
    $index = inv_index([
        'packages/drive/src/Q.php' => <<<'PHP'
            <?php
            namespace Vaulter\Drive;

            class Q
            {
                protected $table = 'v_nodes';

                public function go(): void
                {
                    $db->table('nodes');
                    DB::table('v_drives')->join('nodes', 'a', '=', 'b')->where('nodes', 1);
                    Schema::create('v_nodes', fn () => null);
                    $this->schema->createTable(entity: 'drives');
                    $x = ['drives', 'unrelated'];
                    $t->foreignId('drive_id')->constrained('drives');
                    $db->on('nodes');
                    DB::select('nodes');
                }
            }
            PHP,
        'packages/core/src/Schema/Names.php' => <<<'PHP'
            <?php
            namespace Vaulter\Schema;

            final class Names
            {
                public const ALL = ['nodes', 'v_drives', 'other'];

                public function f(): array
                {
                    $log = 'nodes';
                    $this->model('nodes');
                    throw new RuntimeException('nodes');
                    return match ($x) {
                        'drives' => 1,
                        default => ['nodes' => 2],
                    };
                }
            }
            PHP,
    ]);

    expect(inv_refs($index, 'table'))->toBe([
        ['Vaulter\Drive\Q', 'v_nodes'],
        ['Vaulter\Drive\Q::go', 'nodes'],
        ['Vaulter\Drive\Q::go', 'v_drives'],
        ['Vaulter\Drive\Q::go', 'nodes'],
        ['Vaulter\Drive\Q::go', 'v_nodes'],
        ['Vaulter\Drive\Q::go', 'drives'],
    ])->and(inv_refs($index, 'table-literal'))->toBe([
        ['Vaulter\Schema\Names', 'nodes'],
        ['Vaulter\Schema\Names', 'v_drives'],
        ['Vaulter\Schema\Names::f', 'drives'],
        ['Vaulter\Schema\Names::f', 'nodes'],
    ]);
});

it('takes the table names of the real repository from the migrations of every package', function (): void {
    $index = SourceIndex::forRepo(Repo::discover());
    $migrations = array_filter($index->references('table'), static fn (array $r): bool => str_contains($r['file'], '/database/migrations/') && $r['target'] === 'nodes');

    expect($migrations)->not->toBeEmpty();
});

it('reads vaulter config keys and no other config', function (): void {
    $index = inv_index_one(<<<'PHP'
        <?php
        namespace Vaulter\Drive;

        final class C
        {
            public function go(): void
            {
                config('vaulter-media.disk');
                Config::get('vaulter.tenancy');
                $this->config->has('vaulter-drive.x');
                config('app.name');
                config('vaulter.x', 'default');
                config($key);
                config(key: 'vaulter.named');
                Config::set('vaulter.written', 1);
                $request->attributes->get('vaulter.request_id');
                $cache->get('vaulter.cached');
                $user->has('vaulter.flag');
            }
        }
        PHP);

    expect(inv_targets($index, 'config'))->toBe(['vaulter-media.disk', 'vaulter.tenancy', 'vaulter-drive.x', 'vaulter.x', 'vaulter.named']);
});

it('reads route names from route(), name(), Route::has and URL::route', function (): void {
    $index = inv_index_one(<<<'PHP'
        <?php
        namespace Vaulter\Drive;

        final class R
        {
            public function go(): void
            {
                route('vaulter.nodes.show');
                Route::get('/x')->name('vaulter.x');
                Route::has('vaulter.y');
                URL::route('vaulter.z');
                $request->route('token');
                $this->route('also');
            }
        }
        PHP);

    expect(inv_targets($index, 'route'))->toBe(['vaulter.nodes.show', 'vaulter.x', 'vaulter.y', 'vaulter.z']);
});

it('reads probes with the text of the probed value, whatever the receiver', function (): void {
    $index = inv_index_one(<<<'PHP'
        <?php
        namespace Vaulter\Drive;

        final class P
        {
            public function go(): void
            {
                if (class_exists(\Vaulter\Collaboration\Hub::class) && interface_exists('Vaulter\Foo')) {
                    $this->app->bound(Contract::class);
                    app()->resolved('x');
                    extension_loaded('intl');
                    function_exists('foo');
                }
            }
        }
        PHP);

    expect(inv_refs($index, 'probe'))->toBe([
        ['Vaulter\Drive\P::go', '\Vaulter\Collaboration\Hub::class'],
        ['Vaulter\Drive\P::go', "'Vaulter\\Foo'"],
        ['Vaulter\Drive\P::go', '\Vaulter\Drive\Contract::class'],
        ['Vaulter\Drive\P::go', "'x'"],
        ['Vaulter\Drive\P::go', "'intl'"],
        ['Vaulter\Drive\P::go', "'foo'"],
    ]);
});

it('lists a dynamic class reference apart and does not count it as a name', function (): void {
    $index = inv_index_one(<<<'PHP'
        <?php
        namespace Vaulter\Drive;

        final class D
        {
            public function go(string $class): void
            {
                $class::make();
                new $class();
                $class::CONSTANT;
            }
        }
        PHP);

    expect(array_column($index->dynamicReferences(), 'target'))->toBe(['$class', '$class', '$class'])
        ->and($index->dynamicReferences()[0]['symbol'])->toBe('Vaulter\Drive\D::go')
        ->and($index->references('dynamic'))->toBe([]);
});

it('anchors a reference to the enclosing method, the class or the file', function (): void {
    $index = inv_index_one(<<<'PHP'
        <?php
        namespace Vaulter\Drive;

        use Vaulter\Media\Top;

        #[Attr]
        final class S extends Base
        {
            public ?Prop $p = null;

            public function m(Param $x): Ret
            {
                return (fn () => new Inner())();
            }
        }
        PHP);

    $symbols = [];
    foreach ($index->references('name') as $r) {
        $symbols[$r['target']] = $r['symbol'];
    }

    expect($symbols)->toBe([
        'Vaulter\Drive\Attr' => 'Vaulter\Drive\S',
        'Vaulter\Drive\Base' => 'Vaulter\Drive\S',
        'Vaulter\Drive\Prop' => 'Vaulter\Drive\S',
        'Vaulter\Drive\Param' => 'Vaulter\Drive\S::m',
        'Vaulter\Drive\Ret' => 'Vaulter\Drive\S::m',
        'Vaulter\Drive\Inner' => 'Vaulter\Drive\S::m',
    ])->and(inv_refs($index, 'use'))->toBe([['<file>', 'Vaulter\Media\Top']]);
});

it('gives each method of an anonymous class its own symbol, as migrations are `return new class`', function (): void {
    $index = inv_index_one(<<<'PHP'
        <?php
        use Vaulter\Schema\SchemaBuilder;

        return new class extends Migration
        {
            public function up(): void
            {
                SchemaBuilder::create(entity: 'nodes');
            }

            public function down(): void
            {
                SchemaBuilder::drop(entity: 'nodes');
            }
        };
        PHP, 'packages/core/database/migrations/m.php');

    expect(inv_refs($index, 'table'))->toBe([['<file>@anonymous::up', 'nodes'], ['<file>@anonymous::down', 'nodes']])
        ->and(array_column(inv_refs($index, 'name'), 0))->toContain('<file>@anonymous::up', '<file>@anonymous::down');

    $nested = inv_index_one(<<<'PHP'
        <?php
        namespace Vaulter\Drive;

        final class Outer
        {
            public function make(): object
            {
                return new class
                {
                    public function other(): void
                    {
                        new \Vaulter\Media\C();
                    }
                };
            }
        }
        PHP);

    expect(inv_refs($nested, 'name'))->toBe([['Vaulter\Drive\Outer@anonymous::other', 'Vaulter\Media\C']]);
});

it('declares every kind of type with extends, implements, traits, attributes and method visibility', function (): void {
    $index = inv_index_one(<<<'PHP'
        <?php
        namespace Vaulter\Drive;

        #[Marker]
        final class Impl extends Base implements Iface, Other
        {
            use Concern;

            public function a(): void {}
            protected static function b(): void {}
            private function c(): void {}
        }
        abstract class Abs {}
        class Plain {}
        interface Iface extends Parent1, Parent2 {}
        trait Concern {}
        enum Kind: string implements Iface { case A = 'a'; }
        PHP);

    $kinds = [];
    foreach ($index->declarations() as $d) {
        $kinds[$d['fqcn']] = $d['kind'];
    }
    $impl = $index->declaration('Vaulter\Drive\Impl');

    expect($kinds)->toBe([
        'Vaulter\Drive\Impl' => 'final-class', 'Vaulter\Drive\Abs' => 'abstract-class', 'Vaulter\Drive\Plain' => 'class',
        'Vaulter\Drive\Iface' => 'interface', 'Vaulter\Drive\Concern' => 'trait', 'Vaulter\Drive\Kind' => 'enum',
    ])->and($impl['extends'])->toBe(['Vaulter\Drive\Base'])
        ->and($impl['implements'])->toBe(['Vaulter\Drive\Iface', 'Vaulter\Drive\Other'])
        ->and($impl['traits'])->toBe(['Vaulter\Drive\Concern'])
        ->and($impl['attributes'])->toBe(['Vaulter\Drive\Marker'])
        ->and($impl['methods'])->toBe([
            ['name' => 'a', 'visibility' => 'public', 'static' => false],
            ['name' => 'b', 'visibility' => 'protected', 'static' => true],
            ['name' => 'c', 'visibility' => 'private', 'static' => false],
        ])
        ->and($index->declaration('Vaulter\Drive\Iface')['extends'])->toBe(['Vaulter\Drive\Parent1', 'Vaulter\Drive\Parent2'])
        ->and($index->declaration('Vaulter\Drive\Kind')['implements'])->toBe(['Vaulter\Drive\Iface'])
        ->and($impl['package'])->toBe('drive');
});

it('follows extends through the index to Eloquent Model and finds the package by the longest PSR-4 prefix', function (): void {
    $index = inv_index([
        'packages/core/src/Models/Node.php' => '<?php namespace Vaulter\Models; use Illuminate\Database\Eloquent\Model; class Node extends Model {}',
        'packages/core/src/Models/Leaf.php' => '<?php namespace Vaulter\Models; class Leaf extends Node {}',
        'packages/core/src/Models/Plain.php' => '<?php namespace Vaulter\Models; class Plain extends \Some\Base {}',
        'packages/core/src/Models/Loop.php' => '<?php namespace Vaulter\Models; class Loop extends Loop2 {} class Loop2 extends Loop {}',
    ]);

    expect($index->isEloquent('Vaulter\Models\Leaf'))->toBeTrue()
        ->and($index->isEloquent('\Vaulter\Models\Node'))->toBeTrue()
        ->and($index->isEloquent('Vaulter\Models\Plain'))->toBeFalse()
        ->and($index->isEloquent('Vaulter\Models\Loop'))->toBeFalse()
        ->and($index->isEloquent('Unknown\Thing'))->toBeFalse()
        ->and($index->packageOf('Vaulter\Models\Node'))->toBe('core')
        ->and($index->packageOf('Vaulter\Drive\Access\X'))->toBe('drive')
        ->and($index->packageOf('Vaulter\Collaboration\Hub'))->toBe('collaboration')
        ->and($index->packageOf('Illuminate\X'))->toBeNull();
});

it('is deterministic: two reads of the same sources give identical JSON', function (): void {
    $sources = [
        'packages/drive/src/B.php' => '<?php namespace Vaulter\Drive; use Vaulter\Media\X; class B { function f(): X { return new X(); } }',
        'packages/drive/src/A.php' => '<?php namespace Vaulter\Drive; class A extends B {}',
    ];
    $one = inv_index($sources);
    $two = inv_index(array_reverse($sources, true));
    $dump = static fn (SourceIndex $i): string => json_encode([$i->declarations(), $i->references(), $i->dynamicReferences()], JSON_THROW_ON_ERROR);

    expect($dump($one))->toBe($dump($two))
        ->and(array_column($one->declarations(), 'fqcn'))->toBe(['Vaulter\Drive\A', 'Vaulter\Drive\B']);

    $repo = Repo::discover();
    expect(SourceIndex::forRepo($repo))->toBe(SourceIndex::forRepo($repo));
});

it('indexes the real repository: DriveSchema keeps its private define methods and no reference carries a position', function (): void {
    $index = SourceIndex::forRepo(Repo::discover());
    $builder = $index->declaration('Vaulter\Drive\Schema\DriveSchema');
    $private = array_column(array_filter($builder['methods'] ?? [], static fn (array $m): bool => $m['visibility'] === 'private' && str_starts_with($m['name'], 'define')), 'name');

    expect($builder)->not->toBeNull()->and($private)->toContain('defineNodes')
        ->and(array_keys($index->references()[0]))->toBe(['file', 'symbol', 'kind', 'target'])
        ->and(array_unique(array_map(static fn (array $d): string => (string) $d['package'], $index->declarations())))->toContain('core', 'media', 'drive', 'documents', 'azguard', 'filament');
});

// --- Allowlist ------------------------------------------------------------------------------------------------

/** @return array<string, mixed> */
function inv_entry(array $override = []): array
{
    return [...['view' => 'current', 'file' => 'packages/core/src/A.php', 'symbol' => 'Vaulter\A', 'kind' => 'name', 'target' => 'Vaulter\Drive\B', 'count' => 1, 'reason' => 'legacy', 'removed_in' => 'P4'], ...$override];
}

/** @return array<string, mixed> */
function inv_violation(array $override = []): array
{
    return [...['invariant' => 'I1', 'view' => 'current', 'file' => 'packages/core/src/A.php', 'symbol' => 'Vaulter\A', 'kind' => 'name', 'target' => 'Vaulter\Drive\B', 'count' => 1], ...$override];
}

function inv_list(array $entries, string $mode = 'report', string $invariant = 'I1'): Allowlist
{
    return Allowlist::fromArray(['invariant' => $invariant, 'mode' => $mode, 'entries' => $entries]);
}

it('reports a violation that is not allowed as new', function (): void {
    $result = inv_list([inv_entry()])->compare([inv_violation(), inv_violation(['target' => 'Vaulter\Drive\C'])]);

    expect($result['stale'])->toBe([])
        ->and($result['new'])->toHaveCount(1)
        ->and($result['new'][0]['key'])->toBe('current|packages/core/src/A.php|Vaulter\A|name|Vaulter\Drive\C')
        ->and(Allowlist::describe($result))->toHaveCount(1);
});

it('reports an allowed entry that is no longer found as stale', function (): void {
    $result = inv_list([inv_entry(), inv_entry(['target' => 'Vaulter\Drive\Gone'])])->compare([inv_violation()]);

    expect($result['new'])->toBe([])
        ->and($result['stale'])->toBe([['key' => 'current|packages/core/src/A.php|Vaulter\A|name|Vaulter\Drive\Gone', 'expected' => 1, 'found' => 0]]);
});

it('treats a different count as stale and sums repeated occurrences', function (): void {
    $list = inv_list([inv_entry(['count' => 2])]);

    expect($list->compare([inv_violation(), inv_violation()])['stale'])->toBe([])
        ->and($list->compare([inv_violation(), inv_violation()])['new'])->toBe([])
        ->and($list->compare([inv_violation()])['stale'])->toBe([['key' => 'current|packages/core/src/A.php|Vaulter\A|name|Vaulter\Drive\B', 'expected' => 2, 'found' => 1]])
        ->and($list->compare([inv_violation(), inv_violation(), inv_violation()])['stale'][0]['found'])->toBe(3);
});

it('refuses an entry without a reason or with a bad removed_in', function (): void {
    expect(fn () => inv_list([inv_entry(['reason' => ''])]))->toThrow(InventoryError::class, '"reason" is required')
        ->and(fn () => inv_list([array_diff_key(inv_entry(), ['reason' => 1])]))->toThrow(InventoryError::class, '"reason" is required')
        ->and(fn () => inv_list([inv_entry(['removed_in' => 'later'])]))->toThrow(InventoryError::class, '"removed_in"')
        ->and(fn () => inv_list([array_diff_key(inv_entry(), ['removed_in' => 1])]))->toThrow(InventoryError::class, '"removed_in"')
        ->and(fn () => inv_list([inv_entry(), inv_entry()]))->toThrow(InventoryError::class, 'duplicate entry');
});

it('allows never only for the permanent I4 categories and a non-empty list only in report mode', function (): void {
    expect(fn () => inv_list([inv_entry(['removed_in' => 'never'])]))->toThrow(InventoryError::class, 'never')
        ->and(fn () => inv_list([inv_entry(['removed_in' => 'never', 'category' => 'activation'])], invariant: 'I4'))->toThrow(InventoryError::class, 'never')
        ->and(inv_list([inv_entry(['removed_in' => 'never', 'category' => 'host-lifecycle'])], invariant: 'I4')->entries())->toHaveCount(1)
        ->and(inv_list([], 'block')->compare([inv_violation()])['new'])->toHaveCount(1)
        ->and(fn () => inv_list([inv_entry()], 'block'))->toThrow(InventoryError::class, 'admits only');
});

it('bounds removed_in by the phase an invariant blocks from and requires an I4 category', function (): void {
    expect(fn () => inv_list([inv_entry(['removed_in' => 'P5'])]))->toThrow(InventoryError::class, 'later than I1 blocks (P4)')
        ->and(inv_list([inv_entry(['removed_in' => 'P3'])])->entries())->toHaveCount(1)
        ->and(fn () => inv_list([inv_entry(['removed_in' => 'P4', 'category' => 'activation'])], invariant: 'I4'))->toThrow(InventoryError::class, 'later than I4 blocks (P3)')
        ->and(fn () => inv_list([inv_entry(['removed_in' => 'P3'])], invariant: 'I4'))->toThrow(InventoryError::class, 'I4 needs a "category"')
        ->and(inv_list([inv_entry(['removed_in' => 'P3', 'category' => 'activation'])], invariant: 'I4')->entries())->toHaveCount(1)
        ->and(inv_list([inv_entry(['removed_in' => 'P5', 'category' => 'jwt-optional'])], invariant: 'I4')->entries())->toHaveCount(1);
});

it('D32: an I2 entry naming a package P5 extracts as target_owner may outlive the blocking phase, nothing else may', function (): void {
    $late = ['removed_in' => 'P5', 'category' => 'reverse-edge', 'target_owner' => 'collaboration'];

    expect(inv_list([inv_entry($late)], invariant: 'I2')->entries()[0]['target_owner'])->toBe('collaboration')
        ->and(inv_list([inv_entry($late)], 'block', 'I2')->mode)->toBe('block')
        ->and(fn () => inv_list([inv_entry([...$late, 'target_owner' => 'drive'])], invariant: 'I2'))->toThrow(InventoryError::class, 'later than I2 blocks (P4)')
        ->and(fn () => inv_list([inv_entry([...$late, 'removed_in' => 'P4', 'target_owner' => 'drive'])], 'block', 'I2'))->toThrow(InventoryError::class, 'admits only')
        ->and(fn () => inv_list([inv_entry($late)], invariant: 'I1'))->toThrow(InventoryError::class, 'later than I1 blocks (P4)')
        ->and(fn () => inv_list([inv_entry([...$late, 'removed_in' => 'P3', 'target_owner' => ''])], invariant: 'I2'))->toThrow(InventoryError::class, 'target_owner');
});

// --- Rules ----------------------------------------------------------------------------------------------------

/** @return array<string, mixed> */
function inv_rule(string $match, string $value, string $owner = 'drive', array $extra = []): array
{
    return [...['match' => $match, 'value' => $value, 'owner' => $owner, 'why' => 'test'], ...$extra];
}

/** @return list<array{kind: string, key: string}> */
function inv_types(string ...$fqcns): array
{
    return array_map(static fn (string $fqcn): array => ['kind' => 'type', 'key' => $fqcn], $fqcns);
}

function inv_codes(array $resolution): array
{
    return array_column($resolution['problems'], 'code');
}

it('picks the fqcn rule over a prefix and the longest prefix over a shorter one', function (): void {
    $rules = Rules::fromArray(['all' => ['scope' => ['Vaulter\\'], 'rules' => [
        inv_rule('prefix', 'Vaulter\\', 'core'),
        inv_rule('prefix', 'Vaulter\\Access\\', 'drive'),
        inv_rule('prefix', 'Vaulter\\Access\\Roles\\', 'media'),
        inv_rule('fqcn', 'Vaulter\\Access\\Roles\\Admin', 'documents'),
    ]]]);
    $result = $rules->resolve(inv_types('Vaulter\\Kernel', 'Vaulter\\Access\\Gate', 'Vaulter\\Access\\Roles\\Viewer', 'Vaulter\\Access\\Roles\\Admin'));
    $owners = array_map(static fn (array $w): string => $w['rule']['owner'], $result['winners']);

    expect($result['problems'])->toBe([])->and($owners)->toBe([
        'type:Vaulter\\Access\\Gate' => 'drive',
        'type:Vaulter\\Access\\Roles\\Admin' => 'documents',
        'type:Vaulter\\Access\\Roles\\Viewer' => 'media',
        'type:Vaulter\\Kernel' => 'core',
    ]);
});

it('refuses two rules with the same match and value, in one file or in two', function (): void {
    $rules = Rules::fromArray([
        'a' => ['scope' => ['Vaulter\\A\\'], 'rules' => [inv_rule('prefix', 'Vaulter\\A\\')]],
        'b' => ['scope' => ['Vaulter\\B\\'], 'rules' => [inv_rule('prefix', 'Vaulter\\A\\', 'media')]],
    ]);

    expect(inv_codes($rules->resolve(inv_types('Vaulter\\A\\X'))))->toContain('conflict');
});

it('reports a rule that wins nothing as stale and a subject without a rule as unclassified', function (): void {
    $rules = Rules::fromArray(['all' => ['scope' => ['Vaulter\\'], 'rules' => [
        inv_rule('prefix', 'Vaulter\\A\\'),
        inv_rule('prefix', 'Vaulter\\Unused\\'),
        inv_rule('prefix', 'Vaulter\\A\\Sub\\'),
        inv_rule('fqcn', 'Vaulter\\A\\Sub\\Thing'),
    ]]]);
    $result = $rules->resolve(inv_types('Vaulter\\A\\X', 'Vaulter\\A\\Sub\\Thing', 'Vaulter\\B\\Y'));

    expect(inv_codes($result))->toBe(['unclassified', 'stale-rule', 'stale-rule'])
        ->and(Rules::describe($result['problems']))->toContain('[stale-rule] rule all:prefix:Vaulter\\Unused\\ classifies nothing')
        ->and(Rules::describe($result['problems']))->toContain('[stale-rule] rule all:prefix:Vaulter\\A\\Sub\\ classifies nothing');
});

it('gives a subject to the file with the longest scope and refuses a rule outside the scope of its file', function (): void {
    $files = [
        'wide' => ['scope' => ['Vaulter\\'], 'rules' => [inv_rule('prefix', 'Vaulter\\', 'core')]],
        'access' => ['scope' => ['Vaulter\\Access\\'], 'rules' => [inv_rule('prefix', 'Vaulter\\Access\\')]],
    ];
    $result = Rules::fromArray($files)->resolve(inv_types('Vaulter\\Kernel', 'Vaulter\\Access\\Gate'));

    expect($result['problems'])->toBe([])->and(array_keys($result['winners']))->toBe(['type:Vaulter\\Access\\Gate', 'type:Vaulter\\Kernel']);

    // the wide file's prefix wins a type that belongs to `access`, because `access` has no rule for it
    $files['access']['rules'] = [inv_rule('prefix', 'Vaulter\\Access\\Roles\\')];
    $broken = Rules::fromArray($files)->resolve(inv_types('Vaulter\\Access\\Gate', 'Vaulter\\Access\\Roles\\Admin'));

    expect(inv_codes($broken))->toBe(['out-of-scope'])
        ->and($broken['problems'][0]['files'])->toBe(['wide', 'access'])
        ->and(Rules::fromArray($files)->resolve(inv_types('Vaulter\\Access\\Gate', 'Vaulter\\Access\\Roles\\Admin'), only: 'access')['problems'])->toHaveCount(1);
});

it('refuses equal scopes in two files, an uncovered subject and a file or member rule', function (): void {
    $tie = Rules::fromArray([
        'a' => ['scope' => ['Vaulter\\Same\\'], 'rules' => [inv_rule('prefix', 'Vaulter\\Same\\')]],
        'b' => ['scope' => ['Vaulter\\Same\\'], 'rules' => [inv_rule('prefix', 'Vaulter\\Same\\X', 'media')]],
    ])->resolve(inv_types('Vaulter\\Same\\X'));
    expect(inv_codes($tie))->toContain('scope-tie');

    $rules = Rules::fromArray(['k' => ['scope' => ['Vaulter\\Schema\\', 'path:packages/core/database/'], 'rules' => [
        inv_rule('file', 'packages/core/database/migrations/m.php', 'drive', ['role' => 'migration']),
        inv_rule('member', 'Vaulter\\Schema\\SchemaBuilder::defineNodes'),
    ]]]);
    $subjects = [
        ['kind' => 'file', 'key' => 'packages/core/database/migrations/m.php'],
        ['kind' => 'member', 'key' => 'Vaulter\\Schema\\SchemaBuilder::defineNodes'],
        ['kind' => 'file', 'key' => 'packages/core/config/vaulter.php'],
    ];
    $result = $rules->resolve($subjects);

    expect($result['winners'])->toHaveCount(2)
        ->and(inv_codes($result))->toBe(['uncovered', 'unclassified']);
});

it('treats a scope element without a trailing backslash as that exact type, not as a prefix', function (): void {
    $rules = Rules::fromArray([
        'exact' => ['scope' => ['Vaulter\\Access\\Actor'], 'rules' => [inv_rule('fqcn', 'Vaulter\\Access\\Actor', 'core'), inv_rule('member', 'Vaulter\\Access\\Actor::id', 'core')]],
        'wide' => ['scope' => ['Vaulter\\'], 'rules' => [inv_rule('prefix', 'Vaulter\\')]],
    ]);
    $result = $rules->resolve([...inv_types('Vaulter\\Access\\Actor', 'Vaulter\\Access\\ActorExtra', 'Vaulter\\Access\\Actor\\Child'), ['kind' => 'member', 'key' => 'Vaulter\\Access\\Actor::id']]);

    expect($result['problems'])->toBe([])
        ->and(array_map(static fn (array $w): string => $w['file'], $result['winners']))->toBe([
            'member:Vaulter\\Access\\Actor::id' => 'exact',
            'type:Vaulter\\Access\\Actor' => 'exact',
            'type:Vaulter\\Access\\ActorExtra' => 'wide',
            'type:Vaulter\\Access\\Actor\\Child' => 'wide',
        ]);
});

it('refuses an incomplete rule and an owner outside the allowed list', function (): void {
    $rules = Rules::fromArray(['a' => ['scope' => ['Vaulter\\'], 'rules' => [
        ['match' => 'prefix', 'value' => 'Vaulter\\A\\', 'owner' => 'drive'],
        inv_rule('prefix', 'Vaulter\\B\\', 'nobody'),
        inv_rule('regex', 'Vaulter\\C\\'),
    ]]], owners: ['core', 'drive']);

    expect(inv_codes($rules->resolve([])))->toBe(['invalid-rule', 'invalid-rule', 'invalid-rule'])
        ->and(fn () => Rules::fromArray(['a' => ['scope' => [], 'rules' => []]]))->toThrow(InventoryError::class, 'scope');
});

// --- CLI and the packages generator ---------------------------------------------------------------------------

it('keeps the packages fixture equal to composer.json: inventory.php packages --check exits 0', function (): void {
    [$code, $out, $err] = inv_cli(['packages', '--check']);

    expect($code)->toBe(0, $out.$err)->and($out)->toContain('[packages] ok');

    [$all] = inv_cli(['--check']);
    expect($all)->toBe(0);
});

it('rejects an unknown generator and a missing or doubled mode with exit 2', function (): void {
    [$code, , $err] = inv_cli(['no-such-generator', '--check']);
    expect($code)->toBe(2)->and($err)->toContain('unknown generator no-such-generator');

    expect(inv_cli(['packages'])[0])->toBe(2)->and(inv_cli(['packages', '--check', '--write'])[0])->toBe(2)->and(inv_cli(['packages', '--bogus', '--check'])[0])->toBe(2);
});

it('detects a drift of the packages fixture, rewrites only the verified fields and keeps target_require', function (): void {
    $repo = inv_temp_repo(['packages' => [
        'alpha' => ['dir' => 'packages/alpha', 'exists' => true, 'composer' => 'stale/name', 'psr4' => ['Old\\' => 'src/'], 'current_require' => ['beta'], 'target_require' => []],
        'beta' => ['dir' => 'packages/beta', 'exists' => true, 'target_require' => ['alpha']],
        'gamma' => ['exists' => false, 'psr4' => ['Vaulter\\Gamma\\' => null], 'target_require' => ['beta', 'alpha']],
    ]]);

    try {
        $generator = inv_generator();
        $check = $generator->check($repo);
        expect($check['code'])->toBe(1)
            ->and(implode("\n", $check['lines']))->toContain('-            "composer": "stale/name"')->toContain('+            "composer": "axioma-studio/vaulter-alpha"')->toContain('--write');

        expect($generator->write($repo))->toBe([Repo::PACKAGES_FIXTURE]);
        $packages = $repo->packages();
        expect($generator->check($repo)['code'])->toBe(0)
            ->and($packages['alpha']['current_require'])->toBe([])
            ->and($packages['alpha']['psr4'])->toBe(['Vaulter\\Alpha\\' => 'src/'])
            ->and($packages['beta']['current_require'])->toBe(['alpha'])
            ->and($packages['gamma']['target_require'])->toBe(['alpha', 'beta'])
            ->and($generator->report($repo)['lines'][0])->toContain('alpha')
            ->and(file_get_contents($repo->path(Repo::PACKAGES_FIXTURE)))->toEndWith("}\n");
    } finally {
        inv_remove($repo->root());
    }
});

it('refuses a cyclic or dangling target graph and a prefix claimed twice with exit 2', function (): void {
    $cases = [
        'cycle' => ['alpha' => ['dir' => 'packages/alpha', 'exists' => true, 'target_require' => ['beta']], 'beta' => ['dir' => 'packages/beta', 'exists' => true, 'target_require' => ['alpha']]],
        'unknown' => ['alpha' => ['dir' => 'packages/alpha', 'exists' => true, 'target_require' => ['nope']], 'beta' => ['dir' => 'packages/beta', 'exists' => true, 'target_require' => []]],
        'self' => ['alpha' => ['dir' => 'packages/alpha', 'exists' => true, 'target_require' => ['alpha']], 'beta' => ['dir' => 'packages/beta', 'exists' => true, 'target_require' => []]],
        'prefix' => ['alpha' => ['dir' => 'packages/alpha', 'exists' => true, 'target_require' => []], 'beta' => ['dir' => 'packages/beta', 'exists' => true, 'target_require' => []], 'gamma' => ['exists' => false, 'psr4' => ['Vaulter\\Alpha\\' => null], 'target_require' => []]],
    ];

    foreach ($cases as $name => $packages) {
        $repo = inv_temp_repo(['packages' => $packages]);
        try {
            $check = inv_generator()->check($repo);
            expect($check['code'])->toBe(2, $name)->and($check['lines'][0])->not->toBe('');
        } finally {
            inv_remove($repo->root());
        }
    }
});
