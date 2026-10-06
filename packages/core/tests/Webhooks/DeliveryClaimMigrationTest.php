<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Support\Config;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;

it('upgrades legacy panel tables idempotently on the configured connection and rolls back', function (): void {
    $previousConnection = config('vaulter.connection');
    config()->set('database.connections.claim_upgrade', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);
    config()->set('vaulter.connection', 'claim_upgrade');

    $schema = Schema::connection('claim_upgrade');
    $schema->create('v_webhook_deliveries', static function (Blueprint $table): void {
        $table->id();
        $table->string('status');
    });
    DB::connection('claim_upgrade')->table('v_webhook_deliveries')->insert(['status' => 'failed']);

    $migration = require __DIR__.'/../../database/migrations/2026_09_28_000001_add_webhook_delivery_claims.php';

    try {
        $migration->up();
        $migration->up();
        expect($schema->hasColumns('v_webhook_deliveries', ['claim_token', 'claim_until']))->toBeTrue()
            ->and($schema->hasTable('missing_claim_panel_webhook_deliveries'))->toBeFalse();
        $row = DB::connection('claim_upgrade')->table('v_webhook_deliveries')->first();
        expect($row->status)->toBe('failed')->and($row->claim_token)->toBeNull();

        $migration->down();
        $migration->down();
        expect($schema->hasColumn('v_webhook_deliveries', 'claim_token'))->toBeFalse()
            ->and(DB::connection('claim_upgrade')->table('v_webhook_deliveries')->count())->toBe(1);
    } finally {
        config()->set('vaulter.connection', $previousConnection);
        DB::purge('claim_upgrade');
    }
});

it('preserves an existing delivery through a legacy schema upgrade on the suite driver', function (): void {
    $delivery = WebhookTestHelpers::delivery(WebhookTestHelpers::endpoint(), ['attempts' => 2, 'status' => 'failed']);
    $migration = require __DIR__.'/../../database/migrations/2026_09_28_000001_add_webhook_delivery_claims.php';
    $migration->down();

    expect(Schema::hasColumn(Config::table('webhook_deliveries'), 'claim_token'))->toBeFalse();

    $migration->up();
    $migration->up();

    expect($delivery->refresh()->status)->toBe('failed')
        ->and($delivery->attempts)->toBe(2)
        ->and($delivery->claim_token)->toBeNull()
        ->and($delivery->claim_until)->toBeNull();
});
