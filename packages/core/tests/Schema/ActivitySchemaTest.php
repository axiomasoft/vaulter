<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Vaulter\Enums\ActivityVerb;
use Vaulter\Models\Activity;
use Vaulter\Support\Config;

/**
 * The v_activity schema (§2.9 / M2c-activity): every column exists and the table is
 * APPEND-ONLY — created_at but NO updated_at and NO deleted_at. There is no update
 * or delete path for an audit row.
 */
it('creates the v_activity table with every §2.9 column', function (): void {
    $table = Config::table('activity');

    expect(Schema::hasColumns($table, [
        'id', 'tenant_id', 'storage_id', 'actor_type', 'actor_id', 'verb',
        'target_type', 'target_id', 'context', 'ip', 'user_agent', 'request_id',
        'created_at',
    ]))->toBeTrue();
});

it('is append-only: no updated_at and no soft-delete column', function (): void {
    $table = Config::table('activity');

    expect(Schema::hasColumn($table, 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn($table, 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn($table, 'deleted_at'))->toBeFalse();
});

it('the Activity model keeps timestamps off (no updated_at maintenance)', function (): void {
    $activity = new Activity;
    $activity->forceFill([
        'verb' => ActivityVerb::NodeCreated->value,
        'context' => ['k' => 'v'],
        'created_at' => now(),
    ])->save();

    // It persists against the timestamp-less table and round-trips the verb cast +
    // json context — the model never attempts an updated_at write.
    expect($activity->usesTimestamps())->toBeFalse()
        ->and($activity->refresh()->verb)->toBe(ActivityVerb::NodeCreated)
        ->and($activity->context)->toBe(['k' => 'v']);
});
