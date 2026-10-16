<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Drive\Testing\Sharing\ShareTestHelpers;
use Vaulter\Tests\Support\NodeProbeRoutes;

/**
 * Personal representations are never stored by a shared cache: a validated single
 * resource is `private, no-cache` and varies on the credentials, everything else of
 * v1 (lists, mutations, secrets, bytes, errors, public share answers) is `no-store`.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.features.share_links', true);
    NodeProbeRoutes::register();
    $this->drive = V1::drive();
    $this->actingAs(V1::user());
});

function cacheDirectives(TestResponse $response): array
{
    return array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
}

it('revalidates a single node with its tag and never shares it', function (): void {
    $node = V1::folder($this->drive, 'one');
    $response = $this->getJson(NodeProbeRoutes::path($node->id))->assertOk();

    expect(cacheDirectives($response))->toContain('private', 'no-cache')->not->toContain('public')
        ->and($response->getVary())->toContain('Authorization', 'Cookie');
});

it('never stores lists, mutations, secrets, errors or 428', function (): void {
    $node = V1::folder($this->drive, 'two');

    $responses = [
        'list' => $this->getJson(V1::url('nodes/'.$node->id.'/grants'))->assertOk(),
        'mutation with token' => $this->postJson(V1::url('nodes/'.$node->id.'/shares'), ['role' => 'reader'])->assertCreated(),
        'error' => $this->getJson(V1::url('nodes/missing/grants'))->assertNotFound(),
        '428' => $this->patchJson(NodeProbeRoutes::path($node->id), ['name' => 'x'])->assertStatus(428),
    ];

    foreach ($responses as $case => $response) {
        expect(cacheDirectives($response))->toContain('no-store')->not->toContain('public')->not->toContain('max-age=0')
            ->and($response->headers->has('ETag'))->toBeFalse();
    }
    expect(cacheDirectives($responses['list']))->toContain('private');
});

it('never stores public share answers or their bytes and sends no Referer onward', function (): void {
    $file = V1::file($this->drive, 'public.txt', 'public bytes');
    ShareTestHelpers::share(DriveTestHelpers::model($file), token: 'cache-token');

    foreach (['shares/cache-token', 'shares/cache-token/download', 'shares/unknown-token'] as $path) {
        $response = $this->get(V1::url($path));
        expect(cacheDirectives($response))->toContain('private', 'no-store')
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
    }
});
