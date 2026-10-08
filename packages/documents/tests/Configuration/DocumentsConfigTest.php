<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Vaulter\Contracts\Profiles\CommentsFeature;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

it('enables installed documents and comments while leaving collaboration opt in', function (): void {
    $repository = new Repository(['vaulter-documents' => require __DIR__.'/../../config/vaulter-documents.php']);
    $config = new DocumentsConfig($repository);

    expect($config->commentsEnabled())->toBeTrue()
        ->and($config->collaborationEnabled())->toBeFalse()
        ->and($config->revisionLimit())->toBe(50)
        ->and($config->model('document_revision'))->toBe(DocumentRevision::class)
        ->and($config->exporters()['html'])->toBe(HtmlExporter::class);

    $repository->set('vaulter-documents.revisions.limit', 7);
    $repository->set('vaulter-documents.comments.enabled', false);
    $repository->set('vaulter-documents.collaboration.hub.jwt_secret', 'jwt');
    $repository->set('vaulter-documents.collaboration.hub.webhook_secret', 'webhook');
    $repository->set('vaulter-documents.collaboration.hub.token_ttl', 120);

    expect($config->revisionLimit())->toBe(7)
        ->and($config->commentsEnabled())->toBeFalse()
        ->and($config->hubJwtSecret())->toBe('jwt')
        ->and($config->hubWebhookSecret())->toBe('webhook')
        ->and($config->hubTokenTtl())->toBe(120);
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

it('round trips room identities and filters unsafe extension metadata', function (): void {
    $config = new DocumentsConfig(new Repository(['vaulter-documents' => [
        'collaboration' => ['broadcast' => ['channel_prefix' => 'host.docs']],
        'tiptap' => ['extra_attributes' => ['paragraph' => ['title', false], 'text' => false]],
    ]]));

    expect($config->collaborationRoom('01ABC'))->toBe('host.docs.01ABC')
        ->and($config->nodeIdFromRoom('host.docs.01ABC'))->toBe('01ABC')
        ->and($config->nodeIdFromRoom('foreign.room'))->toBe('foreign.room')
        ->and($config->tiptapExtraAttributes())->toBe(['paragraph' => ['title']]);
});

it('rejects incompatible document model overrides', function (): void {
    $config = new DocumentsConfig(new Repository(['vaulter-documents' => ['models' => ['node_comment' => stdClass::class]]]));

    expect(fn () => $config->model('node_comment'))->toThrow(InvalidConfigurationException::class);
});

it('answers core\'s comments feature question from its own config instead of core reading it', function (): void {
    $feature = app(CommentsFeature::class);

    expect($feature)->toBeInstanceOf(DocumentsConfig::class)->and($feature->enabled())->toBeTrue();

    config()->set('vaulter-documents.comments.enabled', false);

    expect(app(CommentsFeature::class)->enabled())->toBeFalse();
});
