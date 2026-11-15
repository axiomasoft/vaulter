<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Access\ValueObjects\NodeAbilities;
use Vaulter\Drive\Contracts\Handlers\NodePresenter;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Http\Resources\Drive\NodeResource;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

/**
 * The presentation embedded in the node payload: the client routes a
 * node to the right editor/preview/download surface from it — with no mime logic on
 * the client. A document is presented by its document type, a file by
 * its file handler.
 */
it('presents a document node by its document type, not by a file handler', function (): void {
    $drive = DocumentTestHelpers::drive();

    $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Doc',
        content: DocumentTestHelpers::content(),
        parent: DriveTestHelpers::model($drive->root()),
    ));

    $descriptor = app(NodePresenter::class)->present(app(NodeSnapshotMapper::class)->map($node->refresh()));

    expect($descriptor->id)->toBeNull()
        ->and($descriptor->documentType)->toBe('vaulter/rich-text')
        ->and($descriptor->typeAvailable)->toBeTrue()
        ->and($descriptor->capabilities->editable)->toBeTrue()
        ->and($descriptor->capabilities->collaborative)->toBeFalse()
        ->and($descriptor->capabilities->downloadable)->toBeFalse()
        ->and($descriptor->ui->toArray())->toBe(['key' => 'vaulter/tiptap', 'props' => ['preset' => 'rich-text']]);

    app(VaulterDatabase::class)->write(fn () => Document::query()
        ->where('node_id', $node->getKey())->update(['schema_version' => 99]));
    $future = app(NodePresenter::class)->present(app(NodeSnapshotMapper::class)->map($node->refresh()));
    expect($future->capabilities->editable)->toBeFalse()
        ->and($future->capabilities->collaborative)->toBeFalse();

    app(VaulterDatabase::class)->write(fn () => Document::query()
        ->where('node_id', $node->getKey())->update(['type' => 'host/missing', 'schema_version' => 1]));
    $missing = app(NodePresenter::class)->present(app(NodeSnapshotMapper::class)->map($node->refresh()));
    expect($missing->typeAvailable)->toBeFalse()
        ->and($missing->capabilities->editable)->toBeFalse()
        ->and($missing->ui->key)->toBe('vaulter/unsupported');
});

it('embeds the presentation in the NodeResource payload', function (): void {
    $drive = DocumentTestHelpers::drive();

    $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Payload',
        content: DocumentTestHelpers::content(),
        parent: DriveTestHelpers::model($drive->root()),
    ));

    $payload = (new NodeResource(app(NodeSnapshotMapper::class)->map($node), NodeAbilities::denied()))->toArray(request());

    expect($payload)->toHaveKey('presentation')->not->toHaveKey('handler')
        ->and($payload['presentation'])->toBeArray()
        ->and($payload['presentation']['handler'])->toBeNull()
        ->and($payload['presentation']['document_type'])->toBe('vaulter/rich-text')
        ->and($payload['presentation']['type_available'])->toBeTrue()
        ->and($payload['presentation']['capabilities'])->toHaveKey('editable')
        ->and($payload['presentation']['capabilities'])->not->toHaveKey('rich_text')
        ->and($payload['presentation']['viewer'])->toHaveKey('key');
});

it('omits the presentation for a folder node', function (): void {
    $drive = DocumentTestHelpers::drive();
    $folder = $drive->folders()->create('Plain');

    $payload = (new NodeResource($folder, NodeAbilities::denied()))->toArray(request());

    expect($folder->kind)->toBe(NodeKind::Folder)
        ->and($payload['presentation'])->toBeNull();
});

it('describes a file node by its server-trusted mime (never a claimed one)', function (): void {
    // A pdf file node resolves to the preview handler off its persisted mime_type.
    $drive = DocumentTestHelpers::drive();

    $model = config('vaulter-drive.models.node');
    /** @var Node $node */
    $node = new $model;
    app(VaulterDatabase::class)->write(fn () => $node->forceFill([
        'drive_id' => $drive->model()->id,
        'parent_id' => $drive->root()->id,
        'kind' => NodeKind::File,
        'name' => 'scan.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 10,
    ])->save());

    $descriptor = app(NodePresenter::class)->present(app(NodeSnapshotMapper::class)->map($node->refresh()));

    expect($descriptor->id)->toBe('vaulter/preview')
        ->and($descriptor->documentType)->toBeNull()
        ->and($descriptor->capabilities->previewable)->toBeTrue();
});

it('presents docx, md and xlsx FILES as preview/download, never editable (V34)', function (string $name, string $mime): void {
    $drive = DocumentTestHelpers::drive();

    $model = config('vaulter-drive.models.node');
    /** @var Node $node */
    $node = new $model;
    app(VaulterDatabase::class)->write(fn () => $node->forceFill([
        'drive_id' => $drive->model()->id,
        'parent_id' => $drive->root()->id,
        'kind' => NodeKind::File,
        'name' => $name,
        'extension' => pathinfo($name, PATHINFO_EXTENSION),
        'mime_type' => $mime,
        'size_bytes' => 10,
    ])->save());

    $presentation = app(NodePresenter::class)->present(app(NodeSnapshotMapper::class)->map($node->refresh()));

    expect($presentation->id)->toBeIn(['vaulter/preview', 'vaulter/download'])
        ->and($presentation->documentType)->toBeNull()
        ->and($presentation->capabilities->editable)->toBeFalse()
        ->and($presentation->capabilities->collaborative)->toBeFalse();
})->with([
    'docx' => ['report.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'md' => ['notes.md', 'text/markdown'],
    'xlsx' => ['budget.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
]);
