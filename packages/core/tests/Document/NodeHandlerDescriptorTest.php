<?php

declare(strict_types=1);

use Vaulter\Actions\Document\CreateDocumentNodeAction;
use Vaulter\Data\CreateDocumentNodeData;
use Vaulter\Documents\NodeHandlerResolver;
use Vaulter\Enums\NodeKind;
use Vaulter\Http\Resources\Drive\NodeResource;
use Vaulter\Models\Node;
use Vaulter\Tests\Document\DocumentTestHelpers;

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

    $descriptor = app(NodeHandlerResolver::class)->describe($node);

    expect($descriptor->id)->toBe('tiptap')
        ->and($descriptor->capabilities->editable)->toBeTrue()
        ->and($descriptor->capabilities->collaborative)->toBeTrue()
        ->and($descriptor->ui->component)->toBe('VaulterDocEditor');
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
        ->and($payload['handler']['id'])->toBe('tiptap')
        ->and($payload['handler']['capabilities'])->toHaveKey('editable')
        ->and($payload['handler']['ui'])->toHaveKey('component');
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
        'storage_id' => $drive->storage()->id,
        'parent_id' => $drive->root()->id,
        'kind' => NodeKind::File,
        'name' => 'scan.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 10,
    ])->save();

    $descriptor = app(NodeHandlerResolver::class)->describe($node);

    expect($descriptor->id)->toBe('preview')
        ->and($descriptor->capabilities->previewable)->toBeTrue();
});
