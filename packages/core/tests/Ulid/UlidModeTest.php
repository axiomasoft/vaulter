<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Vaulter\Models\Document;
use Vaulter\Support\Config;

it('derives the ulid strategy from the legacy id_type (BC)', function (): void {
    // ids.default is cleared in UlidTestCase; the strategy must fall back to the
    // legacy id_type=ulid knob so existing installs keep their ulid keys.
    expect(config('vaulter.ids.default'))->toBeNull()
        ->and(config('vaulter.id_type'))->toBe('ulid')
        ->and(Config::idStrategy())->toBe('ulid')
        ->and(Config::usesUlids())->toBeTrue();
});

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
