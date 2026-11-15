<?php

declare(strict_types=1);

use Vaulter\Inventory\Allowlist;
use Vaulter\Inventory\InventoryError;
use Vaulter\Inventory\Repo;
use Vaulter\Inventory\Scans\ScanGenerator;
use Vaulter\Inventory\SourceIndex;
use Vaulter\Inventory\Surface;

/*
 * I13 (D27, block mode after P6.3): a type without a surface attribute is unclassified, and a supported (Api or Spi) signature
 * must not name a type marked Internal or an Eloquent model of the domain. Today's findings are listed in
 * tests/Repo/Arch/Fixtures/allowlists/i13.json and must match exactly; the `rules:` tests run the scan on synthetic sources.
 * Seed or refresh: scripts/php84 php scripts/inventory/inventory.php scan-i13 --report --proposals=<path>
 */

require_once dirname(__DIR__, 3).'/scripts/inventory/bootstrap.php';
require_once dirname(__DIR__, 3).'/scripts/inventory/lib/Scans/ScanGenerator.php';
require_once dirname(__DIR__, 3).'/scripts/inventory/lib/Surface.php';

const SR_PSR4 = ['Vaulter\\' => 'core'];

/**
 * @param  array<string, string>      $sources
 * @return list<array<string, mixed>>
 */
function sr_found(array $sources): array
{
    return Surface::violations(Surface::fromSources($sources), SourceIndex::fromSources($sources, SR_PSR4, []));
}

/** @return array<string, string> a supported contract whose signatures name an Internal type three ways, plus clean neighbours */
function sr_sources(): array
{
    return [
        'packages/core/src/Row.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Internal; #[Internal] final class Row {}',
        'packages/core/src/Model.php' => '<?php namespace Vaulter; use Illuminate\Database\Eloquent\Model as Base; use Vaulter\Attributes\Api; #[Api] class Note extends Base {}',
        'packages/core/src/Port.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Api; use Vaulter\Attributes\Internal; #[Api] final class Port {'
            .' public Row $row; public function take(Row $row, ?Row $again, int $n): void {} public function give(): Row|null { return null; } public function note(Note $note): void {}'
            .' #[Internal] public function hook(Row $row): Row { return $row; } public function clean(string $a): self { return $this; } private function secret(Row $row): void {} }',
        'packages/core/src/Quiet.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Internal; #[Internal] final class Quiet { public function take(Row $row): Row { return $row; } }',
    ];
}

it('rules: a supported signature that names an Internal type or an Eloquent model is a violation, at the member that names it', function (): void {
    $found = sr_found(sr_sources());
    $keys = array_map(static fn (array $v): string => $v['symbol'].' '.$v['kind'].' '.$v['target'].' x'.$v['count'].' '.$v['category'], $found);

    expect($keys)->toBe([
        'Vaulter\Port::$row property Vaulter\Row x1 internal-type',
        'Vaulter\Port::give() return Vaulter\Row x1 internal-type',
        'Vaulter\Port::note() param Vaulter\Note x1 domain-model',
        'Vaulter\Port::take() param Vaulter\Row x2 internal-type',
    ]);
});

it('rules: an Internal member, a private member and an Internal type are not checked', function (): void {
    $found = sr_found(sr_sources());

    expect(array_column($found, 'symbol'))->not->toContain('Vaulter\Port::hook()')
        ->and(array_column($found, 'symbol'))->not->toContain('Vaulter\Port::secret()')
        ->and(array_column($found, 'file'))->not->toContain('packages/core/src/Quiet.php');
});

it('rules: a type without a surface attribute is unclassified, and so is a type with two', function (): void {
    $found = sr_found([
        'packages/core/src/Bare.php' => '<?php namespace Vaulter; final class Bare {}',
        'packages/core/src/Both.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Api; use Vaulter\Attributes\Internal; #[Api] #[Internal] final class Both {}',
        'packages/core/src/Fine.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Api; #[Api] final class Fine {}',
    ]);

    expect(array_column($found, 'symbol'))->toBe(['Vaulter\Bare', 'Vaulter\Both'])
        ->and(array_unique(array_column($found, 'view')))->toBe(['unclassified']);
});

it('rules: an exact allowlist is green, an extra finding is new and a gone one is stale', function (): void {
    $found = sr_found(sr_sources());
    $entries = array_map(static fn (array $v): array => ['view' => $v['view'], 'file' => $v['file'], 'symbol' => $v['symbol'], 'kind' => $v['kind'], 'target' => $v['target'], 'count' => $v['count'], 'category' => $v['category'], 'reason' => 'synthetic', 'removed_in' => 'P7'], $found);
    $allowlist = Allowlist::fromArray(['invariant' => 'I13', 'mode' => 'report', 'entries' => $entries]);

    expect(ScanGenerator::evaluate('I13', $found, $allowlist))->toBe(['code' => 0, 'lines' => []]);

    $grown = sr_found([...sr_sources(), 'packages/core/src/Extra.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Api; #[Api] final class Extra { public function row(): Row { return new Row; } }']);
    $new = ScanGenerator::evaluate('I13', $grown, $allowlist);
    expect($new['code'])->toBe(1)->and($new['lines'][0])->toStartWith('new   signature|packages/core/src/Extra.php');

    $shrunk = ScanGenerator::evaluate('I13', array_slice($found, 1), $allowlist);
    expect($shrunk['code'])->toBe(1)->and($shrunk['lines'][0])->toStartWith('stale ');
});

it('rules: I13 entries may be removed in P7, and only a host input stays for ever', function (): void {
    $entry = ['view' => 'signature', 'file' => 'packages/core/src/Port.php', 'symbol' => 'Vaulter\Port::take()', 'kind' => 'param', 'target' => 'Vaulter\Row', 'count' => 1, 'reason' => 'D27', 'removed_in' => 'P7'];
    $load = static fn (string $invariant, array $change): Allowlist => Allowlist::fromArray(['invariant' => $invariant, 'mode' => 'report', 'entries' => [[...$entry, ...$change]]]);

    expect($load('I13', [])->entries())->toHaveCount(1)
        ->and($load('I13', ['removed_in' => 'never', 'category' => 'host-input'])->entries())->toHaveCount(1)
        ->and(fn () => $load('I13', ['removed_in' => 'never', 'category' => 'internal-type']))->toThrow(InventoryError::class, '"never" is only for')
        ->and(fn () => $load('I13', ['removed_in' => 'never']))->toThrow(InventoryError::class, '"never" is only for')
        ->and(fn () => $load('I1', ['category' => 'domain-in-core']))->toThrow(InventoryError::class, 'later than I1 blocks');
});

/**
 * @param  array<string, string> $sources
 * @return list<string>          `symbol kind target category` of the classifier findings the gated scan does not see
 */
function sr_extended(array $sources): array
{
    $classified = Surface::classify(Surface::fromSources($sources), SourceIndex::fromSources($sources, SR_PSR4, []));

    // Every independent extended negative fixture must also fail the blocking scan evaluator.
    if ($classified['extended'] !== []) {
        $blocked = ScanGenerator::evaluate('I13', [...$classified['signature'], ...$classified['extended']], Allowlist::fromArray(['invariant' => 'I13', 'mode' => 'block', 'entries' => []]));
        expect($blocked['code'])->toBe(1)
            ->and(implode('\n', $blocked['lines']))->toContain('new   extended|');
    }

    return array_map(static fn (array $v): string => $v['symbol'].' '.$v['kind'].' '.$v['target'].' '.$v['category'], $classified['extended']);
}

/** @return array<string, string> an Internal row, a domain model and the native ORM factory root the corpus builds on */
function sr_base(): array
{
    return [
        'packages/core/src/Row.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Internal; #[Internal] final class Row {}',
        'packages/core/src/Model.php' => '<?php namespace Vaulter; use Illuminate\Database\Eloquent\Model as Base; use Vaulter\Attributes\Internal; #[Internal] class Note extends Base {}',
        'packages/core/src/Card.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Api; #[Api] final readonly class Card { public function __construct(public string $title) {} }',
    ];
}

it('rules: a technical (Internal or private) constructor hides its parameters, not a public promoted property naming a domain model', function (): void {
    $found = sr_found([...sr_base(),
        'packages/core/src/Wired.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Api; use Vaulter\Attributes\Internal; #[Api] final class Wired { #[Internal] public function __construct(public readonly Note $note, private Row $row, Row $plain) {} }',
        'packages/core/src/Shut.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Api; #[Api] final class Shut { private function __construct(protected Row $row) {} }',
        'packages/core/src/Open.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Api; #[Api] final class Open { public function __construct(Row $row, #[\Vaulter\Attributes\Internal] public Row $trace) {} }',
    ]);

    expect(array_map(static fn (array $v): string => $v['symbol'].' '.$v['kind'].' '.$v['target'].' '.$v['category'], $found))->toBe([
        'Vaulter\Open::__construct() param Vaulter\Row internal-type',
        'Vaulter\Shut::$row property Vaulter\Row internal-type',
        'Vaulter\Wired::$note property Vaulter\Note domain-model',
    ]);
});

it('rules: an ordinary protected member of an extensible supported type stays surfaced; only an Internal technical member is not', function (): void {
    $found = sr_found([...sr_base(),
        'packages/core/src/Base.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Spi; use Vaulter\Attributes\Internal; #[Spi] abstract class Base {'
            .' protected Row $row; protected function hook(Note $note): Row { return new Row; } abstract protected function make(): Card;'
            .' #[Internal] protected function wire(Row $row): void {} }',
    ]);

    expect(array_map(static fn (array $v): string => $v['symbol'].' '.$v['kind'].' '.$v['target'], $found))->toBe([
        'Vaulter\Base::$row property Vaulter\Row',
        'Vaulter\Base::hook() param Vaulter\Note',
        'Vaulter\Base::hook() return Vaulter\Row',
    ]);
});

it('rules: a facade @method naming an Internal type or a domain model is a classifier finding, not a silent skip', function (): void {
    $extended = sr_extended([...sr_base(),
        'packages/core/src/Facade.php' => <<<'PHP'
            <?php
            namespace Vaulter;
            use Vaulter\Attributes\Api;
            use Vaulter\Row as Line;
            /**
             * @method static Line row(int $id)
             * @method static void keep(Note $note, string $why = 'x')
             * @method static Card card()
             * @method static \Vaulter\Ghost ghost()
             */
            #[Api]
            final class Facade {}
            PHP,
    ]);

    expect($extended)->toBe([
        'Vaulter\Facade::ghost() facade-method Vaulter\Ghost review',
        'Vaulter\Facade::keep() facade-method Vaulter\Note domain-model',
        'Vaulter\Facade::row() facade-method Vaulter\Row internal-type',
    ]);
});

it('rules: a generic docblock output or input names its types; templates, shapes and pseudo types are not classes; an unknown Vaulter name is review', function (): void {
    $extended = sr_extended([...sr_base(),
        'packages/core/src/Lists.php' => <<<'PHP'
            <?php
            namespace Vaulter;
            use Illuminate\Support\Collection;
            use Vaulter\Attributes\Api;
            use Vaulter\Attributes\Internal;
            /** @template TKey of array-key */
            #[Api]
            final class Lists
            {
                /** @var list<Row> */
                public array $rows = [];
                /** @return Collection<int, Note> */
                public function notes(): Collection { return new Collection; }
                /** @param array{id: int, card: Card, row: Row|null} $shape @return class-string<Note> */
                public function shape(array $shape): string { return Note::class; }
                /** @template T @param T $value @return array<TKey, non-empty-string|T> */
                public function wrap(mixed $value): array { return []; }
                /** @return iterable<Missing> */
                public function lost(): iterable { return []; }
                /** @return list<Row> */
                #[Internal]
                public function hidden(): array { return []; }
                /** @return list<Row> */
                private function secret(): array { return []; }
            }
            PHP,
    ]);

    expect($extended)->toBe([
        'Vaulter\Lists::$rows doc-var Vaulter\Row internal-type',
        'Vaulter\Lists::lost() doc-return Vaulter\Missing review',
        'Vaulter\Lists::notes() doc-return Vaulter\Note domain-model',
        'Vaulter\Lists::shape() doc-param Vaulter\Row internal-type',
        'Vaulter\Lists::shape() doc-return Vaulter\Note domain-model',
    ]);
});

it('rules: a supported factory on the native ORM Factory with a domain model is a finding; an Internal factory root is not', function (): void {
    $factory = 'use Illuminate\Database\Eloquent\Factories\Factory; use Vaulter\Attributes\Api; use Vaulter\Attributes\Internal;';
    $extended = sr_extended([...sr_base(),
        'packages/core/database/factories/RootFactory.php' => '<?php namespace Vaulter\Factories; '.$factory.' /** @template TModel of \Illuminate\Database\Eloquent\Model @extends Factory<TModel> */ #[Internal] abstract class RootFactory extends Factory { public function made(): \Vaulter\Row { return new \Vaulter\Row; } }',
        'packages/core/database/factories/NoteFactory.php' => '<?php namespace Vaulter\Factories; '.$factory.' use Vaulter\Note; /** @extends RootFactory<Note> */ #[Api] final class NoteFactory extends RootFactory {}',
        'packages/core/database/factories/QuietFactory.php' => '<?php namespace Vaulter\Factories; '.$factory.' use Vaulter\Note; /** @extends Factory<Note> */ #[Internal] final class QuietFactory extends Factory {}',
        'packages/core/database/factories/PlainFactory.php' => '<?php namespace Vaulter\Factories; '.$factory.' use Vaulter\Note; /** @extends Factory<Note> */ #[Api] final class PlainFactory extends Factory {}',
    ]);

    expect($extended)->toBe([
        'Vaulter\Factories\NoteFactory::made() inherited-return Vaulter\Row internal-type',
        'Vaulter\Factories\NoteFactory ancestor Illuminate\Database\Eloquent\Factories\Factory orm-factory',
        'Vaulter\Factories\NoteFactory extends-generic Vaulter\Note domain-model',
        'Vaulter\Factories\PlainFactory ancestor Illuminate\Database\Eloquent\Factories\Factory orm-factory',
        'Vaulter\Factories\PlainFactory extends-generic Vaulter\Note domain-model',
    ]);
});

it('rules: an Internal ancestor or trait does not hide the signatures a supported child inherits; an unknown Vaulter ancestor is review', function (): void {
    $extended = sr_extended([...sr_base(),
        'packages/core/src/Kernel.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Internal; #[Internal] abstract class Kernel { public function row(): Row { return new Row; } /** @return list<Note> */ public function notes(): array { return []; } #[Internal] public function wire(Row $row): void {} private function secret(Row $row): void {} }',
        'packages/core/src/Mixin.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Internal; #[Internal] trait Mixin { protected Note $note; }',
        'packages/core/src/Child.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Api; #[Api] class Child extends Kernel { use Mixin; }',
        'packages/core/src/Orphan.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Api; #[Api] class Orphan extends Gone {}',
        'packages/core/src/Quiet.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Internal; #[Internal] class Quiet extends Kernel {}',
    ]);

    expect($extended)->toBe([
        'Vaulter\Child::$note inherited-property Vaulter\Note domain-model',
        'Vaulter\Child::notes() inherited-doc-return Vaulter\Note domain-model',
        'Vaulter\Child::row() inherited-return Vaulter\Row internal-type',
        'Vaulter\Orphan ancestor Vaulter\Gone review',
    ]);
});

it('rules: a public descriptor or host input DTO is checked like any supported type, and the classifier grants no host-input or Eloquent exception', function (): void {
    $sources = [...sr_base(),
        'packages/core/src/Descriptor.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Api; #[Api] final readonly class Descriptor { public function __construct(public Card $card, public Row $row, public ?Note $note = null) {} }',
        'packages/core/src/Input.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Api; #[Api] final readonly class Input { /** @param list<Note> $notes */ public function __construct(public array $notes) {} }',
    ];
    $classified = Surface::classify(Surface::fromSources($sources), SourceIndex::fromSources($sources, SR_PSR4, []));
    $categories = [...array_column($classified['signature'], 'category'), ...array_column($classified['extended'], 'category')];

    expect(array_map(static fn (array $v): string => $v['symbol'].' '.$v['kind'].' '.$v['target'], $classified['signature']))->toBe([
        'Vaulter\Descriptor::$note property Vaulter\Note',
        'Vaulter\Descriptor::$row property Vaulter\Row',
        'Vaulter\Descriptor::__construct() param Vaulter\Note',
        'Vaulter\Descriptor::__construct() param Vaulter\Row',
    ])
        ->and(array_map(static fn (array $v): string => $v['symbol'].' '.$v['kind'].' '.$v['category'], $classified['extended']))->toBe(['Vaulter\Input::$notes doc-property domain-model', 'Vaulter\Input::__construct() doc-param domain-model'])
        ->and($categories)->not->toContain('host-input')
        ->and(array_column($classified['signature'], 'target_is_eloquent'))->toBe([true, false, true, false]);

    // An allowlist may keep a host input for ever, never a domain model or an Internal type under that name.
    $entry = ['view' => 'signature', 'file' => 'packages/core/src/Descriptor.php', 'symbol' => 'Vaulter\Descriptor::$note', 'kind' => 'property', 'target' => 'Vaulter\Note', 'count' => 1, 'reason' => 'D27', 'removed_in' => 'never'];
    expect(fn () => Allowlist::fromArray(['invariant' => 'I13', 'mode' => 'report', 'entries' => [[...$entry, 'category' => 'domain-model']]]))->toThrow(InventoryError::class, '"never" is only for')
        ->and(ScanGenerator::evaluate('I13', sr_found($sources), Allowlist::fromArray(['invariant' => 'I13', 'mode' => 'report', 'entries' => [[...$entry, 'category' => 'host-input']]]))['lines'])
        ->toContain(...array_map(static fn (string $key): string => 'new   signature|packages/core/src/Descriptor.php|Vaulter\Descriptor::'.$key, ['$row|property|Vaulter\Row (x1)', '__construct()|param|Vaulter\Note (x1)', '__construct()|param|Vaulter\Row (x1)']));
});

it('classifies the current I13 report baseline: every committed allowlist key once, from the source, and every extended finding backed by the source', function (): void {
    // Derived from the committed allowlist and the tree, never pinned: U2/U3 corrections shrink both, U4 empties the allowlist.
    $repo = Repo::discover();
    $types = Surface::read($repo);
    $index = SourceIndex::forRepo($repo);
    $classified = Surface::classify($types, $index);
    $allowlist = Allowlist::load($repo, ScanGenerator::allowlistPath('I13'));
    $surface = array_column($types, 'surface', 'fqcn');
    $allowed = [];
    foreach ($allowlist->entries() as $entry) {
        $allowed[Allowlist::key($entry)] = $entry['category'] ?? null;
    }
    ksort($allowed, SORT_STRING);
    $found = array_column($classified['signature'], 'category', 'key');
    ksort($found, SORT_STRING);

    expect(array_column($classified['signature'], 'key'))->toHaveCount(count($allowed))
        ->and($found)->toBe($allowed)
        ->and(array_filter($classified['signature'], static fn (array $v): bool => $v['declaring_surface'] === 'Internal' || $v['declaring_surface'] === 'unknown' || $v['target_surface'] === 'unknown'))->toBe([])
        ->and(array_values(array_filter($classified['signature'], static fn (array $v): bool => $v['category'] === 'domain-model' && !$v['target_is_eloquent'])))->toBe([])
        ->and(array_column($classified['extended'], 'category'))->not->toContain('review');

    // The native ORM factory root, followed through `extends` of the source index, independently of the classifier walk.
    $onFactory = static function (string $fqcn) use ($index): bool {
        for ($seen = []; !isset($seen[$fqcn]); $fqcn = $declaration['extends'][0]) {
            $seen[$fqcn] = true;
            if ($fqcn === 'Illuminate\Database\Eloquent\Factories\Factory') {
                return true;
            }
            $declaration = $index->declaration($fqcn);
            if ($declaration === null || $declaration['extends'] === []) {
                return false;
            }
        }

        return false;
    };
    $supportedOnFactory = array_keys(array_filter($surface, static fn (string $s, string $fqcn): bool => $s !== 'Internal' && $s !== 'undecided' && $onFactory($fqcn), ARRAY_FILTER_USE_BOTH));
    sort($supportedOnFactory, SORT_STRING);
    $reported = array_values(array_unique(array_column(array_filter($classified['extended'], static fn (array $v): bool => $v['category'] === 'orm-factory'), 'symbol')));
    sort($reported, SORT_STRING);
    expect($reported)->toBe($supportedOnFactory);

    foreach ($classified['extended'] as $v) {
        $declaring = explode('::', $v['symbol'])[0];
        expect(in_array($surface[$declaring] ?? null, ['Api', 'Spi:Implement', 'Spi:Call'], true))->toBeTrue($v['symbol'])
            ->and(match ($v['category']) {
                'domain-model' => $index->isEloquent($v['target']),
                'internal-type' => ($surface[$v['target']] ?? null) === 'Internal',
                'orm-factory' => $v['target'] === 'Illuminate\Database\Eloquent\Factories\Factory',
                default => false,
            })->toBeTrue($v['symbol'].' '.$v['target']);
    }
});

it('matches the committed I13 allowlist to the current tree, exactly', function (): void {
    $generator = require dirname(__DIR__, 3).'/scripts/inventory/generators/scan-i13.php';
    $outcome = $generator->check(Repo::discover());

    expect($outcome['lines'])->toBe([])->and($outcome['code'])->toBe(0);
});

it('keeps every committed I13 entry reasoned, in report mode and never a type without an attribute', function (): void {
    $allowlist = Allowlist::load(Repo::discover(), ScanGenerator::allowlistPath('I13'));
    ScanGenerator::assertReasons($allowlist);

    // Keep the original test identity; the former report baseline is now closed by P6.3/U4.
    expect($allowlist->mode)->toBe('block')
        ->and($allowlist->invariant)->toBe('I13')
        ->and($allowlist->entries())->toBe([]);
});
