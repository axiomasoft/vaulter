<?php

declare(strict_types=1);

use DocVault\Actions\CreateDocumentAction;
use DocVault\Collaboration\HocuspocusCollaborationDriver;
use DocVault\Data\CreateDocumentData;
use DocVault\Enums\DocumentType;
use DocVault\Exceptions\UnknownDocumentTypeException;
use DocVault\Facades\DocVault;
use DocVault\Models\Document;
use DocVault\Tests\Fixtures\AllowResolver;
use DocVault\Tests\Fixtures\TestUser;
use DocVault\ValueObjects\TipTapContent;

beforeEach(function (): void {
    config()->set('docvault.collaboration.driver', 'hocuspocus');
    config()->set('docvault.collaboration.drivers.hocuspocus.secret', 'test-secret');
    config()->set('docvault.collaboration.drivers.hocuspocus.url', 'ws://localhost:1234');
});

it('resolves the hocuspocus driver when configured as default', function (): void {
    expect(DocVault::driver())->toBeInstanceOf(HocuspocusCollaborationDriver::class);
});

it('builds a hocuspocus connection config with a signed room token', function (): void {
    $document = Document::factory()->create();
    $user = (new TestUser)->forceFill(['id' => 7]);

    $config = DocVault::driver()->connectionConfig($document, $user);

    expect($config['driver'])->toBe('hocuspocus')
        ->and($config['url'])->toBe('ws://localhost:1234')
        ->and($config['room'])->toBe((string) $document->id)
        ->and($config['token'])->toBeString()->not->toBe('');
});

it('authorizes the channel through the resolver', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create();

    expect(DocVault::driver()->authorizeChannel((new TestUser)->forceFill(['id' => 1]), $document))->toBeTrue();
});

it('rejects a disabled document type in the create action', function (): void {
    config()->set('docvault.enabled_document_types', ['markdown']);

    app(CreateDocumentAction::class)->execute(new CreateDocumentData(
        workspaceType: 'App\\Project',
        workspaceId: 1,
        type: DocumentType::RichText,
        title: 'Spec',
        content: TipTapContent::empty(),
    ));
})->throws(UnknownDocumentTypeException::class);
