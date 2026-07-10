<?php

declare(strict_types=1);

use Vaulter\Documents\Actions\CreateDocumentNodeAction;
use Vaulter\Documents\Actions\CreateNamedVersionAction;
use Vaulter\Documents\Actions\ReleaseKeptVersionAction;
use Vaulter\Documents\Data\CreateDocumentNodeData;
use Vaulter\Documents\Data\CreateNamedVersionData;
use Vaulter\Documents\Exceptions\NodeDocumentVersionNotFoundException;
use Vaulter\Documents\Models\NodeDocumentVersion;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * F-P1.12-03: before this action existed, keep_forever had a setter (storeVersion)
 * but no way to unset it — a pinned version was excluded from prune FOREVER, not
 * just until released.
 */
function createDocumentForRelease(): Node
{
    $drive = DocumentTestHelpers::drive();

    return app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
        storage: $drive->storage(),
        name: 'Pinned',
        content: DocumentTestHelpers::content('seed'),
        parent: $drive->root(),
    ));
}

it('clears a keep_forever pin, F-P1.12-03', function (): void {
    $node = createDocumentForRelease();

    $pinned = app(CreateNamedVersionAction::class)->execute(new CreateNamedVersionData(
        node: $node->refresh(),
        label: 'pinned',
        keepForever: true,
    ));

    expect($pinned->keep_forever)->toBeTrue();

    $released = app(ReleaseKeptVersionAction::class)->execute($node->refresh(), $pinned->getKey());

    expect($released->getKey())->toEqual($pinned->getKey())
        ->and($released->keep_forever)->toBeFalse()
        ->and(NodeDocumentVersion::query()->whereKey($pinned->getKey())->first()->keep_forever)->toBeFalse();
});

it('a released version re-enters the prune window on the next snapshot, F-P1.12-03', function (): void {
    config()->set('vaulter.versions.max', 1);

    $node = createDocumentForRelease();

    $pinned = app(CreateNamedVersionAction::class)->execute(new CreateNamedVersionData(
        node: $node->refresh(),
        label: 'pinned',
        keepForever: true,
    ));

    // Still pinned: churn does NOT prune it away.
    app(CreateNamedVersionAction::class)->execute(new CreateNamedVersionData(node: $node->refresh(), label: 'v2'));
    expect(NodeDocumentVersion::query()->whereKey($pinned->getKey())->exists())->toBeTrue();

    app(ReleaseKeptVersionAction::class)->execute($node->refresh(), $pinned->getKey());

    // Released: the NEXT snapshot's prune pass can now sweep it (max=1, head excluded).
    app(CreateNamedVersionAction::class)->execute(new CreateNamedVersionData(node: $node->refresh(), label: 'v3'));

    expect(NodeDocumentVersion::query()->whereKey($pinned->getKey())->exists())->toBeFalse();
});

it('throws when releasing a version id that does not belong to the node', function (): void {
    $nodeA = createDocumentForRelease();
    $nodeB = createDocumentForRelease();

    $versionA = NodeDocumentVersion::query()->where('node_id', $nodeA->getKey())->firstOrFail();

    expect(fn () => app(ReleaseKeptVersionAction::class)->execute($nodeB->refresh(), $versionA->getKey()))
        ->toThrow(NodeDocumentVersionNotFoundException::class);
});

it('releases a keep_forever pin through the HTTP surface, F-P1.12-03', function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));

    $storage = DocumentTestHelpers::drive()->storage();

    $nodeId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/documents', [
        'name' => 'Report',
        'content' => ['type' => 'doc', 'content' => []],
    ])->assertCreated()->json('data.id');

    $versionId = $this->postJson('api/vaulter/nodes/'.$nodeId.'/document-versions', [
        'label' => 'Pinned',
        'keep_forever' => true,
    ])->assertCreated()->assertJsonPath('data.keep_forever', true)->json('data.id');

    $this->patchJson('api/vaulter/nodes/'.$nodeId.'/document-versions/'.$versionId.'/keep-forever')
        ->assertOk()
        ->assertJsonPath('data.keep_forever', false);
});
