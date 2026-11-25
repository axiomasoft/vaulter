<?php

declare(strict_types=1);

use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Tests\Schema\ForceCreates;

/**
 * `NodeReadRepository::search()` built `LIKE` from an untrusted
 * term without escaping `%`/`_`/`\`: a term of `%` matched every node
 * (wildcard, not a literal search) and `_` matched any single character.
 * Fixed by escaping the term's LIKE metacharacters before wrapping it in the
 * substring wildcards, with an explicit `ESCAPE '\'` clause.
 */
beforeEach(function (): void {
    $this->storage = ForceCreates::make(Drive::class, [
        'owner_type' => 'team',
        'owner_id' => 1,
        'name' => 'Disk',
    ]);

    // Only the root may be parentless (one per storage); the searched nodes are its children.
    $root = ForceCreates::make(Node::class, [
        'drive_id' => $this->storage->getKey(),
        'parent_id' => null,
        'kind' => NodeKind::Folder,
        'name' => '',
    ]);

    $make = fn (string $name): Node => ForceCreates::make(Node::class, [
        'drive_id' => $this->storage->getKey(),
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

function searchNames(Drive $storage, string $term): array
{
    return app(NodeReadRepository::class)
        ->search($storage, $term)
        ->get()
        ->pluck('name')
        ->sort()
        ->values()
        ->all();
}

it('the term "%" matches ONLY nodes with a literal "%" in the name, not every node', function (): void {
    expect(searchNames($this->storage, '%'))->toBe(['50% report']);
});

it('the term "_" matches ONLY nodes with a literal "_" in the name, not any character', function (): void {
    expect(searchNames($this->storage, '_'))->toBe(['a_b']);
});

it('plain substring search keeps working unchanged', function (): void {
    expect(searchNames($this->storage, 'alph'))->toBe(['alpha']);
});

it('a compound term with "%" inside matches literally', function (): void {
    expect(searchNames($this->storage, '50% rep'))->toBe(['50% report']);
});
