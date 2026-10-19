<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Vaulter\Collaboration\Tests\Collaboration\HubTestHelpers as Hub;
use Vaulter\Collaboration\Tests\Support\Revocation;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

/**
 * POST /v1/collaboration/hub/renew (D26, I17 #9): the hub renews a connection's token before it expires. The rights
 * are checked now, so a renewal never extends authority the subject lost: without View it is refused, without Edit it
 * is read-only, and the new token expires token_ttl from now while the old one keeps its exp.
 */
beforeEach(function (): void {
    Hub::configure();
    ['drive' => $this->drive, 'folder' => $this->folder, 'grant' => $this->grant, 'node' => $this->node] = Revocation::inheritedDocument();
});

function revocationRenew(object $test, string $token, ?string $room = null): TestResponse
{
    return Hub::call('renew', ['room' => $room ?? Hub::room($test->node)], $token);
}

it('renews a session whose rights still hold with a new exp from now, a new jti and the current versions', function (): void {
    $old = Hub::token($this->node);
    $oldClaims = Revocation::claims($old);
    $this->travel(120)->seconds();
    $this->drive->access()->grant($this->folder->id, new GrantInput(subject: Revocation::subject(9), role: RoleRegistry::READER));

    $response = revocationRenew($this, $old)->assertOk()
        ->assertJsonPath('scope', 'write')
        ->assertJsonPath('mode', 'rw')
        ->assertJsonPath('room', Hub::room($this->node))
        ->assertJsonPath('generation', 0);
    $claims = Revocation::claims($response->json('token'));

    expect($claims['exp'])->toBe(now()->getTimestamp() + 300)
        ->and($response->json('exp'))->toBe($claims['exp'])
        ->and($claims['exp'])->toBeGreaterThan($oldClaims['exp'])
        ->and($claims['jti'])->not->toBe($oldClaims['jti'])
        ->and($response->json('jti'))->toBe($claims['jti'])
        ->and($claims['permissions_version'])->toBe(Revocation::versions($this->node)->toClaim())
        ->and($claims['permissions_version']['drive'])->toBeGreaterThan($oldClaims['permissions_version']['drive'])
        ->and(Revocation::claims($old)['exp'])->toBe($oldClaims['exp']);
});

it('refuses to renew a session whose subject lost every right', function (): void {
    $token = Hub::token($this->node);
    $this->drive->access()->revoke($this->folder->id, $this->grant);

    revocationRenew($this, $token)->assertForbidden()->assertJsonPath('code', 'permission_denied');
});

it('renews a writer who lost Edit but kept View read-only', function (): void {
    $token = Hub::token($this->node);
    AccessTestHelpers::grant($this->node, RoleRegistry::READER);
    $this->drive->access()->revoke($this->folder->id, $this->grant);

    $response = revocationRenew($this, $token)->assertOk()->assertJsonPath('scope', 'read')->assertJsonPath('mode', 'ro');

    expect(Revocation::claims($response->json('token'))['scope'])->toBe('read');
});

it('never widens a read-only token, whatever the subject may do now', function (): void {
    revocationRenew($this, Hub::token($this->node, mode: 'ro'))->assertOk()->assertJsonPath('scope', 'read');
});

it('renews read-only while the drive is archived', function (): void {
    $token = Hub::token($this->node);
    $this->drive->archive();

    revocationRenew($this, $token)->assertOk()->assertJsonPath('scope', 'read');
});

it('answers 404 for a document in the trash', function (): void {
    $token = Hub::token($this->node);
    $this->drive->nodes()->trash((string) $this->node->getKey());

    revocationRenew($this, $token)->assertNotFound()->assertJsonPath('code', 'node_not_found');
});

it('answers 409 when the document moved to another generation', function (): void {
    $token = Hub::token($this->node);
    $room = Hub::room($this->node);
    DB::table(app(DatabaseConfig::class)->table('documents'))->where('node_id', $this->node->getKey())->increment('collab_epoch');

    revocationRenew($this, $token, $room)->assertStatus(409)->assertJsonPath('code', 'collaboration_epoch_mismatch');
});

it('accepts a token within the clock skew of its exp and refuses one beyond it', function (): void {
    revocationRenew($this, Hub::token($this->node, claims: ['exp' => time() - 10]))->assertOk();
    revocationRenew($this, Hub::token($this->node, claims: ['exp' => time() - 31]))->assertUnauthorized();
});

it('refuses a body that names another room than the token', function (): void {
    ['node' => $other] = Revocation::inheritedDocument();

    revocationRenew($this, Hub::token($this->node), Hub::room($other))->assertForbidden();
});
