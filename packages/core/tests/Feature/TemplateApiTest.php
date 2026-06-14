<?php

declare(strict_types=1);

use DocVault\Facades\DocVault;
use DocVault\Models\Document;
use DocVault\Tests\Fixtures\AllowResolver;
use DocVault\Tests\Fixtures\NoCreateResolver;
use DocVault\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 61]));
});

function templateDocument(): Document
{
    return Document::factory()->create([
        'type' => 'template',
        'title' => 'Welcome template',
        'content' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'Dear {{ name }},'],
                ]],
            ],
        ],
    ]);
}

it('instantiates a document from a template with variables', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    $template = templateDocument();

    $this->postJson("api/docvault/documents/{$template->id}/instantiate", [
        'variables' => ['name' => 'Ada'],
        'title' => 'Letter to Ada',
    ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Letter to Ada')
        ->assertJsonPath('data.type', 'richtext')
        ->assertJsonPath('data.content.content.0.content.0.text', 'Dear Ada,');

    expect(Document::query()->where('title', 'Letter to Ada')->exists())->toBeTrue();
});

it('instantiates as the requested editable type', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    $template = templateDocument();

    $this->postJson("api/docvault/documents/{$template->id}/instantiate", [
        'variables' => ['name' => 'Ada'],
        'type' => 'markdown',
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'markdown');
});

it('rejects instantiating as another template', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    $template = templateDocument();

    $this->postJson("api/docvault/documents/{$template->id}/instantiate", ['type' => 'template'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

it('rejects instantiating a non-template source', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create(['type' => 'richtext']);

    $this->postJson("api/docvault/documents/{$document->id}/instantiate", ['variables' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('document');
});

it('defaults the title and workspace to the source template', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    $template = templateDocument();

    $this->postJson("api/docvault/documents/{$template->id}/instantiate", ['variables' => ['name' => 'Bob']])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Welcome template')
        ->assertJsonPath('data.content.content.0.content.0.text', 'Dear Bob,');
});

it('forbids instantiation without the create ability', function (): void {
    DocVault::resolvePermissionsWith(new NoCreateResolver);
    $template = templateDocument();

    $this->postJson("api/docvault/documents/{$template->id}/instantiate", ['variables' => []])
        ->assertForbidden();
});
