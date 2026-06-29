<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Vaulter\Models\Document;

it('uses ULID keys when configured', function (): void {
    $document = Document::factory()->create([
        'workspace_type' => 'App\\Project',
        'workspace_id' => (string) Str::ulid(),
    ]);

    expect($document->getKey())->toBeString()
        ->and(strlen((string) $document->getKey()))->toBe(26);

    $this->assertDatabaseHas('v_documents', ['id' => $document->getKey()]);
});

it('adds the tenant column when tenancy is enabled', function (): void {
    expect(Schema::hasColumn('v_documents', 'tenant_id'))->toBeTrue()
        ->and(Schema::hasColumn('v_document_versions', 'tenant_id'))->toBeTrue();
});
