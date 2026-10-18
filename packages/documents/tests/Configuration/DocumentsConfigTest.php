<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Profiles\CommentsProfileOption;
use Vaulter\Drive\Contracts\Profiles\ProfileOption;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Kernel;

it('enables installed documents and comments', function (): void {
    $repository = new Repository(['vaulter-documents' => require __DIR__.'/../../config/vaulter-documents.php']);
    $config = new DocumentsConfig($repository);

    expect($config->commentsEnabled())->toBeTrue()
        ->and($config->revisionLimit())->toBe(50)
        ->and($config->model('document_revision'))->toBe(DocumentRevision::class)
        ->and($config->exporters())->toBe([HtmlExporter::class, MarkdownExporter::class]);

    $repository->set('vaulter-documents.revisions.limit', 7);
    $repository->set('vaulter-documents.comments.enabled', false);

    expect($config->revisionLimit())->toBe(7)
        ->and($config->commentsEnabled())->toBeFalse();
});

it('reads the registered type classes and the default type (PLAN2.P4.3)', function (): void {
    $config = new DocumentsConfig(new Repository(['vaulter-documents' => [
        'types' => ['Host\\KanbanDocumentType'],
        'default_type' => 'host/kanban',
    ]]));

    expect($config->types())->toBe(['Host\\KanbanDocumentType'])
        ->and($config->defaultType())->toBe('host/kanban')
        ->and((new DocumentsConfig(new Repository))->defaultType())->toBe('vaulter/rich-text')
        ->and((new DocumentsConfig(new Repository))->types())->toBe([]);
});

it('refuses a types entry that is not a class name (PLAN2.P4.3)', function (mixed $types): void {
    $config = new DocumentsConfig(new Repository(['vaulter-documents' => ['types' => $types]]));

    expect(fn () => $config->types())->toThrow(InvalidConfigurationException::class);
})->with([
    'closure' => [[static fn (): null => null]],
    'not a list' => ['Host\\KanbanDocumentType'],
]);

it('filters unsafe extension metadata', function (): void {
    $config = new DocumentsConfig(new Repository(['vaulter-documents' => [
        'tiptap' => ['extra_attributes' => ['paragraph' => ['title', false], 'text' => false]],
    ]]));

    expect($config->tiptapExtraAttributes())->toBe(['paragraph' => ['title']]);
});

it('reads the channel prefix of the broadcast channels of a document', function (): void {
    expect((new DocumentsConfig(new Repository))->channelPrefix())->toBe('vaulter.documents')
        ->and((new DocumentsConfig(new Repository(['vaulter-documents' => ['broadcast' => ['channel_prefix' => 'host.docs']]])))->channelPrefix())->toBe('host.docs');
});

it('rejects incompatible document model overrides', function (): void {
    $config = new DocumentsConfig(new Repository(['vaulter-documents' => ['models' => ['node_comment' => stdClass::class]]]));

    expect(fn () => $config->model('node_comment'))->toThrow(InvalidConfigurationException::class);
});

it('answers core\'s comments question through its profile option from its own config', function (): void {
    $option = app(Kernel::class)->extensions()->handler(ProfileOption::class, 'comments');

    expect($option)->toBeInstanceOf(CommentsProfileOption::class)->and($option->enabled())->toBeTrue();

    config()->set('vaulter-documents.comments.enabled', false);

    expect(app(Kernel::class)->extensions()->handler(ProfileOption::class, 'comments')->enabled())->toBeFalse();
});

it('refuses an exporter list that is not class names', function (): void {
    $repository = new Repository(['vaulter-documents' => ['exporters' => [static fn () => null]]]);

    expect(fn () => (new DocumentsConfig($repository))->exporters())->toThrow(InvalidConfigurationException::class, 'class names only');
});
