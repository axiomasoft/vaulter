<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Support\NodeName;

/**
 * Live siblings have distinct name_key (NFC + full case folding), compared
 * byte for byte on every supported database, independent of the host collation.
 */
function nameKeyDrive(): Drive
{
    $drive = new Drive;
    $drive->forceFill(['owner_type' => 'team', 'owner_id' => 1, 'name' => 'Disk'])->save();

    return $drive;
}

/** @param array<string, mixed> $overrides */
function nameKeyNode(Drive $drive, string $name, array $overrides = []): Node
{
    $node = new Node;
    $node->forceFill(['drive_id' => $drive->getKey(), 'parent_id' => null, 'kind' => NodeKind::Folder, 'name' => $name, ...$overrides])->save();

    return $node;
}

it('stores the key with every name write', function (): void {
    $drive = nameKeyDrive();
    $node = nameKeyNode($drive, 'Straße.TXT');

    expect($node->fresh()?->name_key)->toBe(NodeName::key('strasse.txt'));
    $node->forceFill(['name' => 'Other'])->save();
    expect($node->fresh()?->name_key)->toBe('other');
});

it('rejects a live sibling whose name differs only by case, NFC/NFD or full case folding', function (string $first, string $second): void {
    $drive = nameKeyDrive();
    $root = nameKeyNode($drive, 'root');
    nameKeyNode($drive, $first, ['parent_id' => $root->getKey()]);

    // SAVEPOINT: on PostgreSQL the violation would abort the RefreshDatabase transaction.
    expect(fn () => DB::transaction(fn (): Node => nameKeyNode($drive, $second, ['parent_id' => $root->getKey()])))
        ->toThrow(QueryException::class);
})->with([
    'case' => ['Report.pdf', 'report.pdf'],
    'NFC vs NFD' => ['Résumé.pdf', "Re\u{0301}sume\u{0301}.pdf"],
    'full case folding beyond LOWER()' => ['STRASSE', 'straße'],
    'final sigma' => ['ΣΑΣ', 'σας'],
]);

it('accepts the same key once the rival is trashed, and names that differ in edge spaces', function (): void {
    $drive = nameKeyDrive();
    $root = nameKeyNode($drive, 'root');
    nameKeyNode($drive, 'report.pdf', ['parent_id' => $root->getKey(), 'trashed_at' => now()]);

    nameKeyNode($drive, 'Report.pdf', ['parent_id' => $root->getKey()]);
    nameKeyNode($drive, ' report.pdf', ['parent_id' => $root->getKey()]);

    expect(Node::query()->where('parent_id', $root->getKey())->count())->toBe(3);
});

it('lets a node change only the case of its own name', function (): void {
    $drive = nameKeyDrive();
    $root = nameKeyNode($drive, 'root');
    $node = nameKeyNode($drive, 'report.pdf', ['parent_id' => $root->getKey()]);

    $node->forceFill(['name' => 'Report.pdf'])->save();

    expect($node->fresh()?->name)->toBe('Report.pdf');
});

it('backs sibling uniqueness with name_key instead of lower(name)', function (): void {
    $db = app(VaulterDatabase::class)->connection();
    $nodes = app(DatabaseConfig::class)->table('nodes');
    $index = collect($db->getSchemaBuilder()->getIndexes($nodes))->firstWhere('name', $nodes.'_sibling_uniq');

    expect($index)->not->toBeNull()
        ->and($index['unique'])->toBeTrue()
        ->and(array_intersect(['name_key', 'name_key_hash'], $index['columns']))->not->toBeEmpty();
    if ($db->getDriverName() === 'pgsql') {
        expect((string) $db->table('pg_indexes')->where('indexname', $nodes.'_sibling_uniq')->value('indexdef'))->toContain('COLLATE "C"');
    }
});
