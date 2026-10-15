<?php

declare(strict_types=1);

use Vaulter\Inventory\Repo;
use Vaulter\Inventory\SourceIndex;
use Vaulter\Inventory\Surface;

/*
 * The surface manifest of D27 (I13, P3.17): the Api and Spi types of each package with their public and protected members that
 * are not Internal, read from the attributes of the code. The committed packages/<p>/tests/Arch/__snapshots__/surface.txt must be
 * what the code says; the `manifest:` tests run the reader on synthetic sources, so they prove the red as well as the green.
 * Refresh: scripts/php84 php scripts/inventory/inventory.php surface --write
 */

require_once dirname(__DIR__, 3).'/scripts/inventory/bootstrap.php';
require_once dirname(__DIR__, 3).'/scripts/inventory/lib/Surface.php';

/** @return array<string, string> */
function sm_sources(): array
{
    return [
        'packages/core/src/Money.php' => <<<'PHP'
            <?php
            namespace Vaulter;
            use Vaulter\Attributes\Api;
            use Vaulter\Attributes\Internal;
            /** @method static Money zero() */
            #[Api]
            final readonly class Money implements \Countable
            {
                public const CURRENCY = 'EUR';
                protected const STEP = 5;
                private const SECRET = 1;
                #[Internal]
                public const DEBUG = true;
                public function __construct(public int $cents, protected ?string $label = null, private int $hidden = 0, #[Internal] public int $trace = 0) {}
                public function add(self|int $other = 0, string ...$notes): static { return $this; }
                protected static function parse(string &$raw, ?array $options = ['a' => 1]): ?self { return null; }
                private function secret(): void {}
                #[Internal]
                public function hook(): void {}
                public function count(): int { return 0; }
            }
            PHP,
        'packages/core/src/Kind.php' => <<<'PHP'
            <?php
            namespace Vaulter;
            use Vaulter\Attributes\Api;
            #[Api(since: '1.1')]
            enum Kind: string
            {
                case Draft = 'draft';
                case Live = 'live';
                public function label(): string { return $this->value; }
            }
            PHP,
        'packages/core/src/Handler.php' => <<<'PHP'
            <?php
            namespace Vaulter;
            use Vaulter\Attributes\Spi;
            use Vaulter\Attributes\SpiKind;
            #[Spi(SpiKind::Implement)]
            interface Handler extends \Stringable
            {
                public function handle(Money $money, string $key): void;
            }
            PHP,
        'packages/core/src/Hidden.php' => <<<'PHP'
            <?php
            namespace Vaulter;
            use Vaulter\Attributes\Internal;
            #[Internal]
            final class Hidden { public function visible(): void {} }
            PHP,
        'packages/drive/src/Folder.php' => <<<'PHP'
            <?php
            namespace Vaulter\Drive;
            use Vaulter\Attributes\Spi;
            use Vaulter\Attributes\SpiKind;
            #[Spi(kind: SpiKind::Call, since: '1.2')]
            abstract class Folder { abstract public function open(): void; }
            PHP,
    ];
}

it('manifest: lists the Api and Spi types of a package with the members the host can use, and nothing else', function (): void {
    $manifests = Surface::manifests(Surface::fromSources(sm_sources()));

    expect($manifests['core'])->toBe(<<<'TXT'
        # Surface manifest of the package `core` (D27): the types marked #[Api] and #[Spi] with their public and protected members that are not #[Internal].
        # Generated: scripts/php84 php scripts/inventory/inventory.php surface --write. Do not edit by hand.

        Vaulter\Handler spi:implement since 1.0 interface extends Stringable
          public function handle(Vaulter\Money $money, string $key): void

        Vaulter\Kind api since 1.1 enum: string
          case Draft = 'draft'
          case Live = 'live'
          public function label(): string

        Vaulter\Money api since 1.0 final readonly class implements Countable
          @method static Money zero()
          public const CURRENCY = 'EUR'
          protected const STEP = 5
          public int $cents
          protected ?string $label
          public function __construct(int $cents, ?string $label = null, int $hidden = 0, int $trace = 0)
          public function add(int|self $other = 0, string ...$notes): static
          public function count(): int
          protected static function parse(string &$raw, ?array $options = ['a' => 1]): ?self

        TXT)
        ->and($manifests['drive'])->toContain("Vaulter\\Drive\\Folder spi:call since 1.2 abstract class\n  public abstract function open(): void")
        ->and($manifests['media'])->not->toContain('Vaulter\\');
});

it('manifest: is the same whatever the order of the files, and carries no path and no line number', function (): void {
    $sources = sm_sources();
    $forward = Surface::manifests(Surface::fromSources($sources));
    $backward = Surface::manifests(Surface::fromSources(array_reverse($sources, true)));

    expect($backward)->toBe($forward)
        ->and(implode("\n", $forward))->not->toContain('packages/')
        ->and(implode("\n", $forward))->not->toMatch('#/src/|\.php:\d+|line \d+#');
});

it('manifest: a changed signature, a dropped Internal or a new member is a visible difference', function (): void {
    $before = Surface::manifests(Surface::fromSources(sm_sources()))['core'];
    $changes = [
        'a renamed parameter' => ['string $key', 'string $name'],
        'a member no longer Internal' => ["#[Internal]\n    public function hook(): void {}", 'public function hook(): void {}'],
        'a type no longer supported' => ['#[Spi(SpiKind::Implement)]', '#[Internal]'],
    ];
    foreach ($changes as $what => [$from, $to]) {
        $sources = array_map(static fn (string $code): string => str_replace($from, $to, $code), sm_sources());

        expect(Surface::manifests(Surface::fromSources($sources))['core'])->not->toBe($before, $what);
    }
});

it('manifest: an Api type with a technical constructor shows no constructor and still shows its promoted properties', function (): void {
    $code = '<?php namespace Vaulter; use Vaulter\Attributes\Api; use Vaulter\Attributes\Internal; #[Api] final class Wired { #[Internal] public function __construct(public readonly string $id, private int $n = 0) {} public function id(): string { return $this->id; } }';
    $manifest = Surface::manifests(Surface::fromSources(['packages/core/src/Wired.php' => $code]))['core'];

    expect($manifest)->toContain("  public readonly string \$id\n  public function id(): string")
        ->and($manifest)->not->toContain('__construct');
});

it('matches the committed manifest of every package to the code, exactly', function (): void {
    $generator = require dirname(__DIR__, 3).'/scripts/inventory/generators/surface.php';
    $outcome = $generator->check(Repo::discover());

    expect($outcome['lines'])->toBe([])->and($outcome['code'])->toBe(0);
});

it('keeps every type of the repository under exactly one surface attribute', function (): void {
    $repo = Repo::discover();
    $types = Surface::read($repo);
    $index = SourceIndex::forRepo($repo);
    $declared = array_map(static fn (array $d): string => $d['fqcn'], array_filter($index->declarations(), static fn (array $d): bool => str_contains($d['file'], '/src/')));

    expect(array_column(array_filter($types, static fn (array $t): bool => $t['surface'] === 'undecided'), 'fqcn'))->toBe([])
        ->and(count($types))->toBe(count($declared))
        ->and(array_count_values(array_column($types, 'surface')))->toHaveKeys(['Api', 'Internal', 'Spi:Implement']);
});
