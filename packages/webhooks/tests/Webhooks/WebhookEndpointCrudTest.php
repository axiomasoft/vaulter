<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\Testing\Tenancy\MutableTenantResolver;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Repositories\WebhookEndpointReadRepository;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;
use Vaulter\Webhooks\WebhookSecret;

/**
 * The webhook-endpoint management HTTP surface: register / list /
 * update / deactivate / delete. The signing_secret is shown EXACTLY ONCE at
 * registration and never re-serialized. Every action is gated by the manage ability;
 * with no host ability defined, an authenticated user is allowed (the base suite).
 */
beforeEach(function (): void {
    // No host manage ability defined in the base suite → an authenticated user passes
    // the policy (the route still requires auth).
    config()->set('vaulter-webhooks.manage_ability');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

// ─────────────── register — secret shown once ───────────────

it('registers an endpoint and returns the secret exactly once', function (): void {
    $response = $this->postJson('api/vaulter/v1/webhook-endpoints', [
        'url' => 'https://hooks.test/receive',
        'events' => ['folder.created', 'share.created'],
    ])->assertCreated();

    $secret = $response->json('data.secret');

    expect($secret)->toBeString()
        ->and($secret)->toStartWith('whsec_')
        ->and(strlen(WebhookSecret::keyBytes((string) $secret)))->toBe(32)
        ->and($response->json('data.url'))->toBe('https://hooks.test/receive')
        ->and($response->json('data.events'))->toBe(['folder.created', 'share.created'])
        ->and($response->json('data.status'))->toBe('active')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    // The stored secret matches what was returned — and it is NEVER re-serialized.
    $endpoint = WebhookEndpoint::query()->firstOrFail();

    expect($endpoint->signing_secret)->toBe($secret);

    $list = $this->getJson('api/vaulter/v1/webhook-endpoints')->assertOk();

    expect($list->json('data.0'))->not->toHaveKey('secret')
        ->and(array_keys($list->json('data.0')))->toBe(['id', 'url', 'drive_id', 'events', 'status', 'disabled_at', 'version', 'etag', 'created_at'])
        ->and($list->json('meta'))->toBe(['next_cursor' => null, 'per_page' => 50]);
});

it('rejects a registration with an unknown event name', function (): void {
    $this->postJson('api/vaulter/v1/webhook-endpoints', [
        'url' => 'https://hooks.test/receive',
        'events' => ['totally.bogus'],
    ])->assertStatus(422);
});

it('rejects a registration with no events', function (): void {
    $this->postJson('api/vaulter/v1/webhook-endpoints', [
        'url' => 'https://hooks.test/receive',
        'events' => [],
    ])->assertStatus(422);
});

// ─────────────── list / update / deactivate / delete ───────────────

it('lists registered endpoints without ever exposing the secret', function (): void {
    WebhookTestHelpers::endpoint(['url' => 'https://a.test/hook']);
    WebhookTestHelpers::endpoint(['url' => 'https://b.test/hook']);

    $response = $this->getJson('api/vaulter/v1/webhook-endpoints')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0'))->not->toHaveKey('secret');
});

it('updates an endpoint url, events and active flag (partial)', function (): void {
    $endpoint = WebhookTestHelpers::endpoint(['events' => ['folder.created']]);

    $this->patchJson('api/vaulter/v1/webhook-endpoints/'.$endpoint->getKey(), [
        'events' => ['node.moved', 'node.trashed'],
        'is_active' => false,
    ], ['If-Match' => webhookTag($endpoint->getKey())])->assertOk()->assertHeader('ETag', webhookTag($endpoint->getKey(), 1));

    $endpoint->refresh();

    expect($endpoint->events)->toBe(['node.moved', 'node.trashed'])
        ->and($endpoint->is_active)->toBeFalse()
        // The url was not in the PATCH — left unchanged.
        ->and($endpoint->url)->toBe('https://hooks.test/receive');
});

it('deactivates an endpoint and resets its failure_count', function (): void {
    $endpoint = WebhookTestHelpers::endpoint(['is_active' => true, 'failure_count' => 4]);

    $this->postJson('api/vaulter/v1/webhook-endpoints/'.$endpoint->getKey().'/deactivate', [], ['If-Match' => webhookTag($endpoint->getKey())])->assertOk();

    $endpoint->refresh();

    expect($endpoint->is_active)->toBeFalse()
        ->and($endpoint->failure_count)->toBe(0);
});

it('deletes an endpoint', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();

    $this->deleteJson('api/vaulter/v1/webhook-endpoints/'.$endpoint->getKey(), [], ['If-Match' => webhookTag($endpoint->getKey())])->assertNoContent();

    expect(WebhookEndpoint::query()->whereKey($endpoint->getKey())->exists())->toBeFalse();
});

it('404s for an unknown endpoint id', function (): void {
    $this->patchJson('api/vaulter/v1/webhook-endpoints/999999999', [
        'is_active' => false,
    ])->assertNotFound()->assertJsonPath('code', 'webhook_endpoint_not_found');
});

// ─────────────── authorization ───────────────

it('forbids management when the configured manage ability denies', function (): void {
    // Define a host ability and DENY it: the policy must 403 every management action.
    config()->set('vaulter-webhooks.manage_ability', 'vaulter.webhooks.manage');
    Gate::define('vaulter.webhooks.manage', fn (): bool => false);

    $this->postJson('api/vaulter/v1/webhook-endpoints', [
        'url' => 'https://hooks.test/receive',
        'events' => ['folder.created'],
    ])->assertForbidden();

    $this->getJson('api/vaulter/v1/webhook-endpoints')->assertForbidden();
});

it('allows management when the configured manage ability grants', function (): void {
    config()->set('vaulter-webhooks.manage_ability', 'vaulter.webhooks.manage');
    Gate::define('vaulter.webhooks.manage', fn (): bool => true);

    $this->postJson('api/vaulter/v1/webhook-endpoints', [
        'url' => 'https://hooks.test/receive',
        'events' => ['folder.created'],
    ])->assertCreated();
});

it('answers an oversized endpoint id after the ability check: 403 without manage, 404 with it', function (string $oversized): void {
    config()->set('vaulter-webhooks.manage_ability', 'vaulter.webhooks.manage');

    Gate::define('vaulter.webhooks.manage', fn (): bool => false);
    $this->patchJson('api/vaulter/v1/webhook-endpoints/'.$oversized, ['is_active' => false])->assertForbidden();

    Gate::define('vaulter.webhooks.manage', fn (): bool => true);
    $this->patchJson('api/vaulter/v1/webhook-endpoints/'.$oversized, ['is_active' => false])
        ->assertNotFound()->assertJsonPath('code', 'webhook_endpoint_not_found');
})->with(['nineteen digits' => ['9999999999999999999'], 'two hundred digits' => [str_repeat('9', 200)]]);

it('finds an endpoint at the database bigint ceiling without converting its lookup key to an integer', function (): void {
    $endpoint = new class extends WebhookEndpoint
    {
        public function getTable(): string
        {
            return 'identity_endpoint_lookup';
        }
    };
    $db = app(VaulterDatabase::class)->connection();
    $schema = $db->getSchemaBuilder();
    $maximum = in_array($db->getDriverName(), ['mysql', 'mariadb'], true) ? '18446744073709551615' : '9223372036854775807';
    $originalModel = config('vaulter-webhooks.models.webhook_endpoint');

    try {
        // A private temporary table avoids exhausting the real table's MySQL auto-increment counter past rollback.
        $schema->create($endpoint->getTable(), function (Blueprint $table): void {
            $table->temporary();
            $table->unsignedBigInteger('id')->primary();
        });
        $db->table($endpoint->getTable())->insert(['id' => $maximum]);
        config()->set('vaulter-webhooks.models.webhook_endpoint', $endpoint::class);
        $found = app(WebhookEndpointReadRepository::class)->find($maximum);

        expect($found)->not->toBeNull()
            ->and((string) $found->getRawOriginal('id'))->toBe($maximum);
    } finally {
        config()->set('vaulter-webhooks.models.webhook_endpoint', $originalModel);
        $schema->dropIfExists($endpoint->getTable());
    }
});

it('keeps unsigned bigint endpoint identity and tenant isolation across HTTP management', function (string $operation): void {
    $db = app(VaulterDatabase::class)->connection();
    if ($db->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Only MySQL stores entity keys above PHP_INT_MAX.');
    }
    $endpoint = new class extends WebhookEndpoint
    {
        public function getTable(): string
        {
            return 'identity_endpoint_http';
        }
    };
    $originalModel = config('vaulter-webhooks.models.webhook_endpoint');
    $originalTenancy = config('vaulter.tenancy');
    $grammar = $db->getQueryGrammar();
    $table = $endpoint->getTable();
    $maximum = '18446744073709551615';
    $neighbor = (string) PHP_INT_MAX;
    $version = 7;
    $ownSecret = WebhookSecret::generate();
    $foreignSecret = WebhookSecret::generate();

    try {
        // Preserve the actual endpoint column types without advancing the real table's auto-increment counter.
        $db->statement('CREATE TEMPORARY TABLE '.$grammar->wrapTable($table).' LIKE '.$grammar->wrapTable((new WebhookEndpoint)->getTable()));
        foreach ([[$neighbor, 'B', $foreignSecret], [$maximum, 'A', $ownSecret]] as [$id, $tenant, $secret]) {
            $row = (new ($endpoint::class))->forceFill([
                'id' => $id, 'tenant_id' => $tenant, 'url' => 'https://tenant-'.strtolower($tenant).'.test/hook',
                'events' => ['folder.created'], 'signing_secret' => $secret, 'version' => $version,
                'is_active' => true, 'failure_count' => 4, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $db->table($table)->insert($row->getAttributes());
        }
        $foreignBefore = (array) $db->table($table)->where('id', $neighbor)->sole();
        $ownBefore = (array) $db->table($table)->where('id', $maximum)->sole();
        config()->set('vaulter-webhooks.models.webhook_endpoint', $endpoint::class);
        config()->set('vaulter.tenancy.enabled', true);
        config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
        $resolver = new MutableTenantResolver;
        $resolver->tenant = 'A';
        app()->instance(TenantResolver::class, $resolver);
        $path = 'api/vaulter/v1/webhook-endpoints/'.$maximum;
        $shown = $this->getJson($path)->assertOk()->assertJsonPath('data.url', 'https://tenant-a.test/hook');

        // Follow the public If-Match flow: a wrongly clamped response tag must not authorize a neighboring row.
        $headers = ['If-Match' => $shown->headers->get('ETag')];
        $response = match ($operation) {
            'show' => $shown,
            'rotate' => $this->postJson($path.'/rotate-secret', ['overlap_seconds' => 600], $headers),
            'update' => $this->patchJson($path, ['url' => 'https://own-updated.test/hook', 'events' => ['node.moved']], $headers),
            'deactivate' => $this->postJson($path.'/deactivate', [], $headers),
            'delete' => $this->deleteJson($path, [], $headers),
        };

        // Check the other tenant before response identity assertions: the complete HTTP write must not touch it.
        expect((array) $db->table($table)->where('id', $neighbor)->first())->toBe($foreignBefore);
        if ($operation === 'delete') {
            $response->assertNoContent();
            expect($db->table($table)->where('id', $maximum)->exists())->toBeFalse();
            $this->getJson($path)->assertNotFound();
        } else {
            $response->assertOk();
            $expectedVersion = $operation === 'show' ? $version : $version + 1;
            $response->assertJsonPath('data.id', $maximum)
                ->assertJsonPath('data.version', $expectedVersion)
                ->assertHeader('ETag', webhookTag($maximum, $expectedVersion));
            expect($response->json('data.etag'))->toBe(webhookTag($maximum, $expectedVersion));
            $ownAfter = (array) $db->table($table)->where('id', $maximum)->sole();
            expect((string) $ownAfter['id'])->toBe($maximum)->and($ownAfter['tenant_id'])->toBe('A')
                ->and((int) $ownAfter['version'])->toBe($expectedVersion);

            if ($operation === 'rotate') {
                $newSecret = $response->json('data.secret');
                expect($newSecret)->toStartWith('whsec_')->not->toBe($ownSecret)->not->toBe($foreignSecret)
                    ->and(Crypt::decryptString($ownAfter['signing_secret']))->toBe($newSecret)
                    ->and(Crypt::decryptString($ownAfter['previous_signing_secret']))->toBe($ownSecret)
                    ->and($ownAfter['signing_secret'])->not->toContain($newSecret);
                $again = $this->getJson($path)->assertOk()->assertJsonPath('data.id', $maximum)
                    ->assertHeader('ETag', webhookTag($maximum, $expectedVersion));
                expect($again->json('data'))->not->toHaveKey('secret');
            } else {
                expect($ownAfter['signing_secret'])->toBe($ownBefore['signing_secret'])
                    ->and($response->json('data'))->not->toHaveKey('secret');
            }
            if ($operation === 'update') {
                expect($ownAfter['url'])->toBe('https://own-updated.test/hook')
                    ->and(json_decode($ownAfter['events'], true, flags: JSON_THROW_ON_ERROR))->toBe(['node.moved']);
            }
            if ($operation === 'deactivate') {
                expect((bool) $ownAfter['is_active'])->toBeFalse()->and((int) $ownAfter['failure_count'])->toBe(0)
                    ->and($ownAfter['disabled_reason'])->toBe('manual');
            }
        }
        $shown->assertJsonPath('data.id', $maximum)->assertJsonPath('data.etag', webhookTag($maximum, $version))
            ->assertHeader('ETag', webhookTag($maximum, $version));
        expect($shown->json('data'))->not->toHaveKey('secret');
        $this->getJson('api/vaulter/v1/webhook-endpoints/'.$neighbor)->assertNotFound();
    } finally {
        config()->set('vaulter-webhooks.models.webhook_endpoint', $originalModel);
        config()->set('vaulter.tenancy', $originalTenancy);
        app()->forgetInstance(TenantResolver::class);
        $db->getSchemaBuilder()->dropIfExists($table);
    }
})->with(['show', 'rotate', 'update', 'deactivate', 'delete'])->group('engine:mysql');
