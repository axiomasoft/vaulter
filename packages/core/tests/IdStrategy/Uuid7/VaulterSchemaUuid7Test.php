<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Vaulter\Schema\VaulterSchema;
use Vaulter\Tests\IdStrategy\SchemaProbe;

beforeEach(fn () => SchemaProbe::build());
afterEach(fn () => SchemaProbe::drop());

it('emits every convention column under the uuid7 strategy', function (): void {
    $table = SchemaProbe::TABLE;

    expect(Schema::hasColumns($table, [
        'id', 'profile', 'tenant_id', 'drive_id', 'parent_id',
        'owner_type', 'owner_id', 'created_by', 'permissions',
    ]))->toBeTrue();
});

it('shapes Vaulter keys and references as uuid columns and host keys independently', function (): void {
    expect(SchemaProbe::emittedType('id', fn (Blueprint $t) => VaulterSchema::key($t)))->toBe('uuid')
        ->and(SchemaProbe::emittedType('drive_id', fn (Blueprint $t) => VaulterSchema::reference($t, 'drive_id')))->toBe('uuid')
        ->and(SchemaProbe::emittedType('created_by', fn (Blueprint $t) => VaulterSchema::actor($t, 'created_by')))->toBe('string')
        ->and(SchemaProbe::emittedType('tenant_id', fn (Blueprint $t) => VaulterSchema::tenant($t)))->toBe('string')
        ->and(SchemaProbe::emittedType('owner_id', fn (Blueprint $t) => VaulterSchema::morphs($t, 'owner')))->toBe('uuid');
});

it('defaults the panel discriminator and carries a bitmask column', function (): void {
    expect(SchemaProbe::emittedType('profile', fn (Blueprint $t) => VaulterSchema::panel($t)))->toBe('string')
        ->and(SchemaProbe::emittedType('permissions', fn (Blueprint $t) => VaulterSchema::bits($t, 'permissions')))->toBe('integer');
});
