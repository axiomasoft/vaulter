<?php

declare(strict_types=1);

use DocVault\Contracts\DocumentExporter;
use DocVault\Facades\DocVault;
use DocVault\Models\Document;
use DocVault\Tests\Fixtures\AllowResolver;
use DocVault\Tests\Fixtures\DenyResolver;
use DocVault\Tests\Fixtures\TestUser;
use DocVault\ValueObjects\TipTapContent;

beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 21]));
});

function exportContent(): array
{
    return [
        'type' => 'doc',
        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hi']]]],
    ];
}

it('exports a document as HTML', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create(['title' => 'My Doc', 'content' => exportContent()]);

    $this->getJson(sprintf('api/docvault/documents/%s/export?format=html', $document->id))
        ->assertOk()
        ->assertJsonPath('data.format', 'html')
        ->assertJsonPath('data.filename', 'my-doc.html')
        ->assertJsonPath('data.content', fn (string $content): bool => str_contains($content, '<p>Hi</p>'));
});

it('exports a document as Markdown', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create(['title' => 'My Doc', 'content' => exportContent()]);

    $this->getJson(sprintf('api/docvault/documents/%s/export?format=markdown', $document->id))
        ->assertOk()
        ->assertJsonPath('data.format', 'markdown')
        ->assertJsonPath('data.filename', 'my-doc.md')
        ->assertJsonPath('data.content', fn (string $content): bool => trim($content) === 'Hi');
});

it('defaults to HTML when no format is given', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create();

    $this->getJson(sprintf('api/docvault/documents/%s/export', $document->id))
        ->assertOk()
        ->assertJsonPath('data.format', 'html');
});

it('forbids exporting when the resolver denies view', function (): void {
    DocVault::resolvePermissionsWith(new DenyResolver);
    $document = Document::factory()->create();

    $this->getJson(sprintf('api/docvault/documents/%s/export', $document->id))->assertForbidden();
});

it('rejects an unsupported export format', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create();

    $this->getJson(sprintf('api/docvault/documents/%s/export?format=pdf', $document->id))->assertStatus(422);
});

it('exports through a registered custom exporter', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    DocVault::registerExporter(new class implements DocumentExporter
    {
        public function format(): string
        {
            return 'json';
        }

        public function mimeType(): string
        {
            return 'application/json';
        }

        public function extension(): string
        {
            return 'json';
        }

        public function export(TipTapContent $content): string
        {
            return 'custom-export-body';
        }
    });

    $document = Document::factory()->create(['title' => 'My Doc', 'content' => exportContent()]);

    $this->getJson(sprintf('api/docvault/documents/%s/export?format=json', $document->id))
        ->assertOk()
        ->assertJsonPath('data.format', 'json')
        ->assertJsonPath('data.filename', 'my-doc.json')
        ->assertJsonPath('data.content', 'custom-export-body');
});

it('includes the exporter mime type in the response', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create(['title' => 'My Doc', 'content' => exportContent()]);

    $this->getJson(sprintf('api/docvault/documents/%s/export?format=html', $document->id))
        ->assertOk()
        ->assertJsonPath('data.mime_type', 'text/html');
});

it('supports a binary exporter returning base64 content', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    DocVault::registerExporter(new class implements DocumentExporter
    {
        public function format(): string
        {
            return 'pdf';
        }

        public function mimeType(): string
        {
            return 'application/pdf';
        }

        public function extension(): string
        {
            return 'pdf';
        }

        public function export(TipTapContent $content): string
        {
            return base64_encode('%PDF-1.4 fake');
        }
    });

    $document = Document::factory()->create(['title' => 'My Doc', 'content' => exportContent()]);

    $response = $this->getJson(sprintf('api/docvault/documents/%s/export?format=pdf', $document->id))
        ->assertOk()
        ->assertJsonPath('data.format', 'pdf')
        ->assertJsonPath('data.filename', 'my-doc.pdf')
        ->assertJsonPath('data.mime_type', 'application/pdf');

    expect(base64_decode((string) $response->json('data.content')))->toBe('%PDF-1.4 fake');
});
