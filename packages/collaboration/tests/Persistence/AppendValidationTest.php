<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Vaulter\Collaboration\Tests\Collaboration\HubTestHelpers as Hub;
use Vaulter\Collaboration\Tests\Support\DocumentsV1 as Docs;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Models\Node;

/*
 * Scenario 17: the append refuses what it cannot take before it allocates or writes — a body above
 * limits.request_bytes by its Content-Length (ahead of the signature check), a payload above limits.update_bytes by its
 * base64 length, a malformed envelope, another protocol version — and a subject who cannot see the document gets the
 * same 404 as a document that does not exist.
 */

function validRows(): int
{
    return DB::table(app(DatabaseConfig::class)->table('collaboration_updates'))->count()
        + DB::table(app(DatabaseConfig::class)->table('collaboration_receipts'))->count()
        + DB::table(app(DatabaseConfig::class)->table('collaboration_heads'))->count();
}

/** @return array<string, mixed> a problem body without its per-request id */
function validProblem(TestResponse $response): array
{
    $body = $response->json();
    unset($body['request_id']);

    return $body;
}

beforeEach(function (): void {
    Hub::configure();
    $this->node = Hub::document();
});

it('refuses a body above limits.request_bytes by its Content-Length before the signature is checked', function (): void {
    config()->set('vaulter-collaboration.limits.request_bytes', 512);

    // No token and no signature: the size gate answers before anything reads the body.
    $this->call('POST', Docs::url('collaboration/hub/append'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '4096'], str_repeat('x', 4096))
        ->assertStatus(413)
        ->assertJsonPath('code', 'collaboration_update_too_large')
        ->assertJsonPath('meta.context.max_bytes', 512);
    expect(validRows())->toBe(0);
});

it('refuses a payload above limits.update_bytes by its base64 length, and takes one at the limit', function (): void {
    config()->set('vaulter-collaboration.limits.update_bytes', 30);

    Hub::callV2('append', Hub::appendBody($this->node, str_repeat('a', 31)), Hub::token($this->node))
        ->assertStatus(413)
        ->assertJsonPath('code', 'collaboration_update_too_large');
    expect(validRows())->toBe(0);

    Hub::callV2('append', Hub::appendBody($this->node, str_repeat('a', 30)), Hub::token($this->node))
        ->assertOk()
        ->assertJsonPath('seq', 1);
});

it('bounds a seed by max_state_bytes, not limits.update_bytes: it carries the whole initial state', function (): void {
    config()->set('vaulter-collaboration.limits.update_bytes', 30);
    config()->set('vaulter-collaboration.max_state_bytes', 60);

    Hub::callV2('append', Hub::seedBody($this->node, str_repeat('s', 61)), Hub::token($this->node))
        ->assertStatus(413)
        ->assertJsonPath('code', 'collaboration_update_too_large');
    expect(validRows())->toBe(0);

    Hub::callV2('append', Hub::seedBody($this->node, str_repeat('s', 60)), Hub::token($this->node))
        ->assertOk()
        ->assertJsonPath('seq', 1);
    // Every later record is one update again.
    Hub::callV2('append', Hub::appendBody($this->node, str_repeat('a', 31)), Hub::token($this->node))
        ->assertStatus(413)
        ->assertJsonPath('code', 'collaboration_update_too_large');
});

it('refuses a malformed envelope with 422 and writes nothing', function (string $field, mixed $value): void {
    $body = Hub::appendBody($this->node, 'update');
    $body[$field] = $value;

    Hub::callV2('append', $body, Hub::token($this->node))
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
    expect(validRows())->toBe(0);
})->with([
    'update_id not a uuid' => ['update_id', 'not-a-uuid'],
    'sha256 not hex' => ['sha256', 'xyz'],
    'payload not base64' => ['payload', '%%%not base64%%%'],
    'payload empty' => ['payload', ''],
    'negative generation' => ['generation', -1],
    'generation as a string' => ['generation', '0'],
    'unknown origin' => ['origin', 'redis'],
]);

it('refuses a claimed sha256 that is not the hash of the payload with 422 collaboration_update_mismatch', function (): void {
    Hub::callV2('append', Hub::appendBody($this->node, 'update', sha256: hash('sha256', 'other')), Hub::token($this->node))
        ->assertStatus(422)
        ->assertJsonPath('code', 'collaboration_update_mismatch')
        ->assertJsonPath('meta.context.reason', 'hash');
    expect(validRows())->toBe(0);
});

it('refuses a call without protocol 2 with 409 collaboration_protocol_mismatch', function (?string $protocol): void {
    Hub::callV2('append', Hub::appendBody($this->node, 'update'), Hub::token($this->node), protocol: $protocol)
        ->assertStatus(409)
        ->assertJsonPath('code', 'collaboration_protocol_mismatch')
        ->assertJsonPath('retryable', 'never');
    expect(validRows())->toBe(0);
})->with(['no header' => [null], 'protocol 1' => ['1'], 'protocol 3' => ['3']]);

it('refuses a read-only token and a subject who may only read with 403', function (): void {
    Hub::callV2('append', Hub::appendBody($this->node, 'update'), Hub::token($this->node, mode: 'ro'))->assertForbidden();

    $reader = Hub::document(level: RoleRegistry::READER, actorId: 2);
    Hub::callV2('append', Hub::appendBody($reader, 'update'), Hub::token($reader, actorId: 2))->assertForbidden();
    expect(validRows())->toBe(0);
});

it('answers a hidden document, another tenant and a missing document with the same 404', function (): void {
    $hidden = Hub::document(level: null);
    $tenant = Hub::document();
    $missing = Hub::document();
    $missingToken = Hub::token($missing);
    $missingBody = Hub::appendBody($missing, 'update');
    Node::query()->whereKey($missing->getKey())->delete();

    $answers = [
        validProblem(Hub::callV2('append', Hub::appendBody($hidden, 'update'), Hub::token($hidden))->assertNotFound()),
        validProblem(Hub::callV2('append', Hub::appendBody($tenant, 'update'), Hub::token($tenant, claims: ['tenant' => 'other-tenant']))->assertNotFound()),
        validProblem(Hub::callV2('append', $missingBody, $missingToken)->assertNotFound()),
    ];

    expect(array_column($answers, 'code'))->toBe(['node_not_found', 'node_not_found', 'node_not_found'])
        ->and(array_map(static fn (array $answer): array => array_diff_key($answer, ['detail' => true, 'meta' => true]), $answers))
        ->sequence(
            fn ($first) => $first->toBe(array_diff_key($answers[2], ['detail' => true, 'meta' => true])),
            fn ($second) => $second->toBe(array_diff_key($answers[2], ['detail' => true, 'meta' => true])),
            fn ($third) => $third->toBeArray(),
        )
        ->and(validRows())->toBe(0);
});
