<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Vaulter\Support\VaulterSchema;
use Vaulter\Tests\IdStrategy\SchemaProbe;

beforeEach(fn () => SchemaProbe::build());
afterEach(fn () => SchemaProbe::drop());

it('emits every convention column under the uuid7 strategy', function (): void {
    $table = SchemaProbe::TABLE;

    expect(Schema::hasColumns($table, [
        'id', 'panel', 'tenant_id', 'storage_id', 'parent_id',
        'owner_type', 'owner_id', 'created_by', 'permissions', 'extra_data',
    ]))->toBeTrue();
});

it('shapes Vaulter keys and references as uuid columns and host keys independently', function (): void {
    expect(SchemaProbe::emittedType('id', fn ($t) => VaulterSchema::key($t)))->toBe('uuid')
        ->and(SchemaProbe::emittedType('storage_id', fn ($t) => VaulterSchema::reference($t, 'storage_id')))->toBe('uuid')
        ->and(SchemaProbe::emittedType('created_by', fn ($t) => VaulterSchema::actor($t, 'created_by')))->toBe('string')
        ->and(SchemaProbe::emittedType('tenant_id', fn ($t) => VaulterSchema::tenant($t)))->toBe('string')
        ->and(SchemaProbe::emittedType('owner_id', fn ($t) => VaulterSchema::morphs($t, 'owner')))->toBe('uuid');
});

it('defaults the panel discriminator and carries a bitmask column', function (): void {
    expect(SchemaProbe::emittedType('panel', fn ($t) => VaulterSchema::panel($t)))->toBe('string')
        ->and(SchemaProbe::emittedType('permissions', fn ($t) => VaulterSchema::bits($t, 'permissions')))->toBe('integer');
});
