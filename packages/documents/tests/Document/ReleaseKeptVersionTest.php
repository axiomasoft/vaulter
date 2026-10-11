<?php

declare(strict_types=1);

use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Revisions\CreateNamedRevisionAction;
use Vaulter\Documents\Actions\Revisions\UpdateRevisionRetentionAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;
use Vaulter\Documents\Data\Revisions\UpdateRevisionRetentionInput;
use Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * F-P1.12-03: before this action existed, keep_forever had a setter (storeVersion)
 * but no way to unset it — a pinned version was excluded from prune FOREVER, not
 * just until released.
 */
function createDocumentForRelease(): Node
{
    $drive = DocumentTestHelpers::drive();

    return app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Pinned',
        content: DocumentTestHelpers::content('seed'),
        parent: DriveTestHelpers::model($drive->root()),
    ));
}

it('clears a keep_forever pin, F-P1.12-03', function (): void {
    $node = createDocumentForRelease();

    $pinned = app(CreateNamedRevisionAction::class)->execute(DocumentTestHelpers::context($node->refresh()), new CreateNamedRevisionInput(
        node: $node->refresh(),
        label: 'pinned',
        keepForever: true,
    ));

    expect($pinned->keep_forever)->toBeTrue();

    $released = app(UpdateRevisionRetentionAction::class)->execute(DocumentTestHelpers::context($node), new UpdateRevisionRetentionInput(node: $node->refresh(), revision: $pinned->getKey(), keepForever: false));

    expect($released->getKey())->toEqual($pinned->getKey())
        ->and($released->keep_forever)->toBeFalse()
        ->and(DocumentRevision::query()->whereKey($pinned->getKey())->first()->keep_forever)->toBeFalse();
});

it('a released version re-enters the prune window on the next snapshot, F-P1.12-03', function (): void {
    config()->set('vaulter-documents.revisions.limit', 1);

    $node = createDocumentForRelease();

    $pinned = app(CreateNamedRevisionAction::class)->execute(DocumentTestHelpers::context($node->refresh()), new CreateNamedRevisionInput(
        node: $node->refresh(),
        label: 'pinned',
        keepForever: true,
    ));

    // Still pinned: churn does NOT prune it away.
    app(CreateNamedRevisionAction::class)->execute(DocumentTestHelpers::context($node->refresh()), new CreateNamedRevisionInput(node: $node->refresh(), label: 'v2'));
    expect(DocumentRevision::query()->whereKey($pinned->getKey())->exists())->toBeTrue();

    app(UpdateRevisionRetentionAction::class)->execute(DocumentTestHelpers::context($node), new UpdateRevisionRetentionInput(node: $node->refresh(), revision: $pinned->getKey(), keepForever: false));

    // Released: the NEXT snapshot's prune pass can now sweep it (max=1, head excluded).
    app(CreateNamedRevisionAction::class)->execute(DocumentTestHelpers::context($node->refresh()), new CreateNamedRevisionInput(node: $node->refresh(), label: 'v3'));

    expect(DocumentRevision::query()->whereKey($pinned->getKey())->exists())->toBeFalse();
});

it('throws when releasing a version id that does not belong to the node', function (): void {
    $nodeA = createDocumentForRelease();
    $nodeB = createDocumentForRelease();

    $versionA = DocumentTestHelpers::head($nodeA);

    expect(fn () => app(UpdateRevisionRetentionAction::class)->execute(DocumentTestHelpers::context($nodeB), new UpdateRevisionRetentionInput(node: $nodeB->refresh(), revision: $versionA->getKey(), keepForever: false)))
        ->toThrow(DocumentRevisionNotFoundException::class);
});

it('releases a keep_forever pin through the HTTP surface, F-P1.12-03', function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));

    $storage = DocumentTestHelpers::drive()->model();

    $nodeId = $this->postJson(Docs::url('drives/'.$storage->getKey().'/documents'), [
        'name' => 'Report',
        'content' => ['type' => 'doc', 'content' => []],
    ])->assertCreated()->json('data.id');

    $versionId = $this->postJson(Docs::url('documents/'.$nodeId.'/revisions'), [
        'label' => 'Pinned',
        'keep_forever' => true,
    ])->assertCreated()->assertJsonPath('data.keep_forever', true)->json('data.id');

    $this->patchJson(Docs::url('documents/'.$nodeId.'/revisions/'.$versionId), ['keep_forever' => false])
        ->assertOk()
        ->assertJsonPath('data.keep_forever', false);
});
