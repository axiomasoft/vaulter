<?php

declare(strict_types=1);

use DocVault\Models\Document;
use DocVault\Support\Config;
use Illuminate\Support\Facades\Schema;

it('creates every table with the configured prefix', function (): void {
    expect(Schema::hasTable('custom_documents'))->toBeTrue()
        ->and(Schema::hasTable('custom_document_folders'))->toBeTrue()
        ->and(Schema::hasTable('custom_document_versions'))->toBeTrue()
        ->and(Schema::hasTable('custom_document_permissions'))->toBeTrue()
        ->and(Schema::hasTable('custom_document_comments'))->toBeTrue()
        ->and(Schema::hasTable('custom_document_shares'))->toBeTrue()
        ->and(Schema::hasTable('dv_documents'))->toBeFalse();
});

it('resolves config and model tables through the prefix', function (): void {
    expect(Config::table('documents'))->toBe('custom_documents')
        ->and((new Document)->getTable())->toBe('custom_documents');
});

it('persists records into the prefixed table', function (): void {
    $document = Document::factory()->create([
        'workspace_type' => 'App\\Project',
        'workspace_id' => 1,
    ]);

    $this->assertDatabaseHas('custom_documents', ['id' => $document->getKey()]);
});
