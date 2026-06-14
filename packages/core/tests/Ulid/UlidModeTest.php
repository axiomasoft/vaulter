<?php

declare(strict_types=1);

use DocVault\Models\Document;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('uses ULID keys when configured', function (): void {
    $document = Document::factory()->create([
        'workspace_type' => 'App\\Project',
        'workspace_id' => (string) Str::ulid(),
    ]);

    expect($document->getKey())->toBeString()
        ->and(strlen((string) $document->getKey()))->toBe(26);

    $this->assertDatabaseHas('dv_documents', ['id' => $document->getKey()]);
});

it('adds the tenant column when tenancy is enabled', function (): void {
    expect(Schema::hasColumn('dv_documents', 'tenant_id'))->toBeTrue()
        ->and(Schema::hasColumn('dv_document_versions', 'tenant_id'))->toBeTrue();
});
