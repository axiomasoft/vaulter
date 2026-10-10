<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;

it('leaves host routes without problem+json and without Vaulter headers', function (): void {
    Route::get('/host/boom', static function (): never {
        throw NodeNotFoundException::forId(1);
    });

    $response = $this->getJson('/host/boom');

    expect($response->headers->get('Content-Type'))->not->toContain('problem+json')
        ->and($response->headers->has('X-Vaulter-Api-Version'))->toBeFalse()
        ->and($response->headers->has('X-Request-Id'))->toBeFalse();

    $this->get('/host/missing', ['Accept' => 'application/json'])
        ->assertNotFound()
        ->assertHeaderMissing('X-Vaulter-Api-Version');
});

it('keeps the legacy body on unversioned Vaulter routes', function (): void {
    $prefix = trim(app(RoutesConfig::class)->prefix(), '/');
    Route::get($prefix.'/__legacy', static function (): never {
        throw NodeNotFoundException::forId(1);
    });
    // `v10` is not the `v1` surface.
    Route::get($prefix.'/v10/__legacy', static function (): never {
        throw NodeNotFoundException::forId(1);
    });

    foreach ([$prefix.'/__legacy', $prefix.'/v10/__legacy'] as $path) {
        $response = $this->getJson($path)->assertStatus(404)->assertJsonPath('code', 'node_not_found');

        expect($response->json())->toHaveKeys(['message', 'code'])
            ->and($response->headers->get('Content-Type'))->not->toContain('problem+json')
            ->and($response->headers->has('X-Vaulter-Api-Version'))->toBeFalse();
    }
});
