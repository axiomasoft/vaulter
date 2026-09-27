<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Vaulter\Models\Webhooks\WebhookEndpoint;

uses(RefreshDatabase::class);

it('keeps a control endpoint scoped to its cell and exact sink profile', function (): void {
    expect(config('database.connections.testing.database'))->toBe(':memory:');
    Artisan::call('load:queue-probe', [
        'action' => 'endpoints', '--run-id' => 'r09-test', '--cell-id' => 'product',
        '--count' => '1', '--profile' => '2xx',
    ]);
    foreach (['control', 'control'] as $cell) {
        Artisan::call('load:queue-probe', [
            'action' => 'delivery', '--run-id' => 'r09-test', '--cell-id' => $cell,
            '--count' => '4', '--profile' => '500',
        ]);
    }
    $endpoints = WebhookEndpoint::query()->orderBy('id')->get();
    expect($endpoints)->toHaveCount(2)
        ->and($endpoints[0]->url)->toContain('/2xx?')->toContain('cell_id=product')
        ->and($endpoints[1]->url)->toContain('/500?')->toContain('cell_id=control');
});
