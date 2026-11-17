<?php

declare(strict_types=1);

use Packman\Config\Exceptions\InvalidConfiguration;
use Vaulter\Documents\Configuration\DocumentsOptions;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Profiles\CommentsProfileOption;
use Vaulter\Drive\Contracts\Profiles\ProfileOption;
use Vaulter\Kernel;

it('enables installed documents and comments', function (): void {
    $raw = require __DIR__.'/../../config/vaulter-documents.php';
    $config = DocumentsOptions::fromArray($raw);

    expect($config->comments->enabled)->toBeTrue()
        ->and($config->revisions->limit)->toBe(50)
        ->and($config->model('document_revision'))->toBe(DocumentRevision::class)
        ->and($config->exporters)->toBe([HtmlExporter::class, MarkdownExporter::class]);

    $raw['revisions']['limit'] = 7;
    $raw['comments']['enabled'] = false;
    $custom = DocumentsOptions::fromArray($raw);

    expect($custom->revisions->limit)->toBe(7)
        ->and($custom->comments->enabled)->toBeFalse();
});

it('reads the registered type classes and the default type (PLAN2.P4.3)', function (): void {
    $config = DocumentsOptions::fromArray([
        'types' => ['Host\\KanbanDocumentType'],
        'default_type' => 'host/kanban',
    ]);

    expect($config->types)->toBe(['Host\\KanbanDocumentType'])
        ->and($config->defaultType)->toBe('host/kanban')
        ->and(DocumentsOptions::fromArray([])->defaultType)->toBe('vaulter/rich-text')
        ->and(DocumentsOptions::fromArray([])->types)->toBe([]);
});

it('refuses a types entry that is not a class name (PLAN2.P4.3)', function (mixed $types): void {
    expect(fn (): DocumentsOptions => DocumentsOptions::fromArray(['types' => $types]))->toThrow(InvalidConfiguration::class);
})->with([
    'closure' => [[static fn (): null => null]],
    'not a list' => ['Host\\KanbanDocumentType'],
]);

it('filters unsafe extension metadata', function (): void {
    $config = DocumentsOptions::fromArray([
        'tiptap' => ['extra_attributes' => ['paragraph' => ['title', false], 'text' => false]],
    ]);

    expect($config->tiptap->extraAttributes)->toBe(['paragraph' => ['title']]);
});

it('reads the channel prefix of the broadcast channels of a document', function (): void {
    expect(DocumentsOptions::fromArray([])->broadcast->channelPrefix)->toBe('vaulter.documents')
        ->and(DocumentsOptions::fromArray(['broadcast' => ['channel_prefix' => 'host.docs']])->broadcast->channelPrefix)->toBe('host.docs');
});

it('rejects incompatible document model overrides', function (): void {
    expect(fn (): DocumentsOptions => DocumentsOptions::fromArray(['models' => ['node_comment' => stdClass::class]]))->toThrow(InvalidConfiguration::class);
});

it('answers core\'s comments question through its profile option from its own config', function (): void {
    $option = app(Kernel::class)->extensions()->handler(ProfileOption::class, 'comments');

    expect($option)->toBeInstanceOf(CommentsProfileOption::class)->and($option->enabled())->toBeTrue();

    config()->set('vaulter-documents.comments.enabled', false);

    expect(app(Kernel::class)->extensions()->handler(ProfileOption::class, 'comments')->enabled())->toBeFalse();
});

it('refuses an exporter list that is not class names', function (): void {
    expect(fn (): DocumentsOptions => DocumentsOptions::fromArray(['exporters' => [static fn (): null => null]]))->toThrow(InvalidConfiguration::class, 'class names only');
});
