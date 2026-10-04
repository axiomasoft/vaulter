<?php

declare(strict_types=1);

use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Tests\Schema\ForceCreates;

/**
 * F-P1.8-03 — `NodeReadRepository::search()` built `LIKE` from an untrusted
 * term without escaping `%`/`_`/`\`: a term of `%` matched every node
 * (wildcard, not a literal search) and `_` matched any single character.
 * Fixed by escaping the term's LIKE metacharacters before wrapping it in the
 * substring wildcards, with an explicit `ESCAPE '\'` clause.
 */
beforeEach(function (): void {
    $this->storage = ForceCreates::make(Storage::class, [
        'owner_type' => 'team',
        'owner_id' => 1,
        'name' => 'Disk',
    ]);

    // Only the root may be parentless (one per storage); the searched nodes are its children.
    $root = ForceCreates::make(Node::class, [
        'storage_id' => $this->storage->getKey(),
        'parent_id' => null,
        'kind' => NodeKind::Folder,
        'name' => '',
    ]);

    $make = fn (string $name): Node => ForceCreates::make(Node::class, [
        'storage_id' => $this->storage->getKey(),
        'parent_id' => $root->getKey(),
        'kind' => NodeKind::Folder,
        'name' => $name,
    ]);

    // A literal '%' and a literal '_' in real node names, plus two ordinary
    // names that must NOT surface for an escaped-wildcard term.
    $this->percentNode = $make('50% report');
    $this->underscoreNode = $make('a_b');
    $this->ordinaryOne = $make('alpha');
    $this->ordinaryTwo = $make('bravo');
});

function searchNames(Storage $storage, string $term): array
{
    return app(NodeReadRepository::class)
        ->search($storage, $term)
        ->get()
        ->pluck('name')
        ->sort()
        ->values()
        ->all();
}

it('термин "%" матчит ТОЛЬКО узлы с литеральным "%" в имени, не все узлы [F-P1.8-03]', function (): void {
    expect(searchNames($this->storage, '%'))->toBe(['50% report']);
});

it('термин "_" матчит ТОЛЬКО узлы с литеральным "_" в имени, не любой символ [F-P1.8-03]', function (): void {
    expect(searchNames($this->storage, '_'))->toBe(['a_b']);
});

it('обычный подстроковый поиск продолжает работать без изменений [F-P1.8-03]', function (): void {
    expect(searchNames($this->storage, 'alph'))->toBe(['alpha']);
});

it('поиск по составному термину с "%" внутри матчит буквально [F-P1.8-03]', function (): void {
    expect(searchNames($this->storage, '50% rep'))->toBe(['50% report']);
});
