<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;

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

it('leaves a path that is not the v1 surface to the host exception handler', function (): void {
    $prefix = trim(app(RoutesConfig::class)->prefix(), '/');
    // `v10` is not the `v1` surface, and nothing else under the prefix is Vaulter's any more.
    foreach ([$prefix.'/__unversioned', $prefix.'/v10/__unversioned'] as $path) {
        Route::get($path, static function (): never {
            throw NodeNotFoundException::forId(1);
        });

        $response = $this->getJson($path)->assertStatus(500);

        expect($response->headers->get('Content-Type'))->not->toContain('problem+json')
            ->and($response->headers->has('X-Vaulter-Api-Version'))->toBeFalse();
    }
});
