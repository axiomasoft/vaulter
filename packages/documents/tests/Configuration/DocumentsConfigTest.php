<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Vaulter\Contracts\Profiles\ProfileOption;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Data\Collaboration\RoomRef;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Profiles\CommentsProfileOption;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Kernel;

it('enables installed documents and comments while leaving collaboration opt in', function (): void {
    $repository = new Repository(['vaulter-documents' => require __DIR__.'/../../config/vaulter-documents.php']);
    $config = new DocumentsConfig($repository);

    expect($config->commentsEnabled())->toBeTrue()
        ->and($config->collaborationEnabled())->toBeFalse()
        ->and($config->revisionLimit())->toBe(50)
        ->and($config->model('document_revision'))->toBe(DocumentRevision::class)
        ->and($config->exporters())->toBe([HtmlExporter::class, MarkdownExporter::class]);

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

    $room = $config->collaborationRoom('01DRIVE', '01ABC', 3);

    expect($room)->toBe('host.docs.01DRIVE.01ABC.3')
        ->and($config->parseRoom($room))->toEqual(new RoomRef(driveId: '01DRIVE', nodeId: '01ABC', epoch: 3))
        ->and($config->tiptapExtraAttributes())->toBe(['paragraph' => ['title']]);
});

it('parses only room names it issues', function (string $room): void {
    $config = new DocumentsConfig(new Repository(['vaulter-documents' => [
        'collaboration' => ['broadcast' => ['channel_prefix' => 'host.docs']],
    ]]));

    expect($config->parseRoom($room))->toBeNull();
})->with([
    'foreign prefix' => ['foreign.room.1.2.0'],
    'no epoch (0.2 room)' => ['host.docs.42'],
    'missing drive' => ['host.docs.42.0'],
    'extra segment' => ['host.docs.1.2.3.4'],
    'empty node' => ['host.docs.1..0'],
    'negative epoch' => ['host.docs.1.2.-1'],
    'padded epoch' => ['host.docs.1.2.01'],
    'non-numeric epoch' => ['host.docs.1.2.x'],
]);

it('refuses the removed reverb driver and a token ttl above one hour', function (): void {
    $reverb = new DocumentsConfig(new Repository(['vaulter-documents' => ['collaboration' => ['driver' => 'reverb']]]));
    $ttl = static fn (int $seconds): DocumentsConfig => new DocumentsConfig(new Repository(['vaulter-documents' => ['collaboration' => ['hub' => ['token_ttl' => $seconds]]]]));

    expect(fn () => $reverb->collaborationDriver())->toThrow(InvalidConfigurationException::class, 'reverb collaboration driver was removed')
        ->and(fn () => $ttl(3601)->hubTokenTtl())->toThrow(InvalidConfigurationException::class)
        ->and(fn () => $ttl(0)->hubTokenTtl())->toThrow(InvalidConfigurationException::class)
        ->and($ttl(3600)->hubTokenTtl())->toBe(3600)
        ->and((new DocumentsConfig(new Repository([])))->collaborationMaxStateBytes())->toBe(16777216);
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

it('refuses a JWT secret equal to the webhook secret, in every environment (R9)', function (): void {
    $repository = new Repository(['vaulter-documents' => ['collaboration' => ['enabled' => true, 'hub' => [
        'jwt_secret' => 'one-value-for-both', 'webhook_secret' => 'one-value-for-both',
    ]]]]);

    expect(fn () => (new DocumentsConfig($repository))->assertDistinctHubSecrets())
        ->toThrow(InvalidConfigurationException::class, 'must differ');

    // Different, missing, or collaboration off: nothing to refuse here (core warns about "missing").
    foreach ([['a', 'b', true], ['a', null, true], [null, null, true], ['same', 'same', false]] as [$jwt, $webhook, $enabled]) {
        $repository->set('vaulter-documents.collaboration', ['enabled' => $enabled, 'hub' => ['jwt_secret' => $jwt, 'webhook_secret' => $webhook]]);
        (new DocumentsConfig($repository))->assertDistinctHubSecrets();
    }

    expect(true)->toBeTrue();
});
