<?php

declare(strict_types=1);

use Vaulter\Actions\CreateDocumentAction;
use Vaulter\Collaboration\HocuspocusCollaborationDriver;
use Vaulter\Data\CreateDocumentData;
use Vaulter\Enums\DocumentType;
use Vaulter\Exceptions\UnknownDocumentTypeException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Document;
use Vaulter\Tests\Fixtures\AllowResolver;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\ValueObjects\TipTapContent;

beforeEach(function (): void {
    config()->set('vaulter.collaboration.driver', 'hocuspocus');
    config()->set('vaulter.collaboration.drivers.hocuspocus.secret', 'test-secret');
    config()->set('vaulter.collaboration.drivers.hocuspocus.url', 'ws://localhost:1234');
});

it('resolves the hocuspocus driver when configured as default', function (): void {
    expect(Vaulter::driver())->toBeInstanceOf(HocuspocusCollaborationDriver::class);
});

it('builds a hocuspocus connection config with a signed room token', function (): void {
    $document = Document::factory()->create();
    $user = (new TestUser)->forceFill(['id' => 7]);

    $config = Vaulter::driver()->connectionConfig($document, $user);

    expect($config['driver'])->toBe('hocuspocus')
        ->and($config['url'])->toBe('ws://localhost:1234')
        ->and($config['room'])->toBe((string) $document->id)
        ->and($config['token'])->toBeString()->not->toBe('');
});

it('authorizes the channel through the resolver', function (): void {
    Vaulter::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create();

    expect(Vaulter::driver()->authorizeChannel((new TestUser)->forceFill(['id' => 1]), $document))->toBeTrue();
});

it('rejects a disabled document type in the create action', function (): void {
    config()->set('vaulter.enabled_document_types', ['markdown']);

    app(CreateDocumentAction::class)->execute(new CreateDocumentData(
        workspaceType: 'App\\Project',
        workspaceId: 1,
        type: DocumentType::RichText,
        title: 'Spec',
        content: TipTapContent::empty(),
    ));
})->throws(UnknownDocumentTypeException::class);
