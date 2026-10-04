<?php

declare(strict_types=1);

use Vaulter\Contracts\Handlers\NodePresenter;
use Vaulter\Documents\Actions\Documents\CreateDocumentNodeAction;
use Vaulter\Documents\Data\Documents\CreateDocumentNodeData;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Http\Resources\Drive\NodeResource;
use Vaulter\Models\Drive\Node;

/**
 * The handler descriptor embedded in the node payload (§4.5 / §5 / M4a): the client
 * routes a node to the right editor/preview/download surface from id + capabilities
 * + ui — with no mime logic on the client.
 */
it('resolves a document node to the TipTap handler descriptor', function (): void {
    $drive = DocumentTestHelpers::drive();

    $node = app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
        storage: $drive->storage(),
        name: 'Doc',
        content: DocumentTestHelpers::content(),
        parent: $drive->root(),
    ));

    $descriptor = app(NodePresenter::class)->present($node);

    expect($descriptor->id)->toBe('vaulter/tiptap')
        ->and($descriptor->capabilities->editable)->toBeTrue()
        ->and($descriptor->capabilities->collaborative)->toBeTrue()
        ->and($descriptor->ui->key)->toBe('vaulter/tiptap');
});

it('embeds the handler descriptor in the NodeResource payload', function (): void {
    $drive = DocumentTestHelpers::drive();

    $node = app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
        storage: $drive->storage(),
        name: 'Payload',
        content: DocumentTestHelpers::content(),
        parent: $drive->root(),
    ));

    $payload = NodeResource::make($node)->toArray(request());

    expect($payload)->toHaveKey('handler')
        ->and($payload['handler'])->toBeArray()
        ->and($payload['handler']['id'])->toBe('vaulter/tiptap')
        ->and($payload['handler']['capabilities'])->toHaveKey('editable')
        ->and($payload['handler']['ui'])->toHaveKey('key');
});

it('omits the handler descriptor for a folder node', function (): void {
    $drive = DocumentTestHelpers::drive();
    $folder = $drive->createFolder('Plain');

    $payload = NodeResource::make($folder)->toArray(request());

    expect($folder->kind)->toBe(NodeKind::Folder)
        ->and($payload['handler'])->toBeNull();
});

it('describes a file node by its server-trusted mime (never a claimed one)', function (): void {
    // A pdf file node resolves to the preview handler off its persisted mime_type.
    $drive = DocumentTestHelpers::drive();

    $model = config('vaulter.models.node');
    /** @var Node $node */
    $node = new $model;
    $node->forceFill([
        'drive_id' => $drive->storage()->id,
        'parent_id' => $drive->root()->id,
        'kind' => NodeKind::File,
        'name' => 'scan.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 10,
    ])->save();

    $descriptor = app(NodePresenter::class)->present($node);

    expect($descriptor->id)->toBe('vaulter/preview')
        ->and($descriptor->capabilities->previewable)->toBeTrue();
});
