<?php

declare(strict_types=1);

use Vaulter\Collaboration\Tests\Collaboration\HubTestHelpers as Hub;
use Vaulter\Collaboration\Tests\Support\Compaction;
use Vaulter\Collaboration\Tests\Support\Revocation;
use Vaulter\Drive\Access\AccessInvalidation;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

/**
 * A revoked writer's append (D26, I17 #8): the append re-checks the subject's rights every time, so whatever revoked
 * them — the grant on an ancestor, a move, a cut of inheritance, an archive, a host change the drive never saw — the
 * next append is refused, with or without a control message. A token older than its drive's permission version is
 * only a hint: the append still answers by the rights now and tells the hub the token is stale.
 */
beforeEach(function (): void {
    Hub::configure();
    ['drive' => $this->drive, 'folder' => $this->folder, 'grant' => $this->grant, 'node' => $this->node] = Revocation::inheritedDocument();
    $this->token = Hub::token($this->node);
});

it('appends for a writer whose rights hold, with a current token that is not stale', function (): void {
    Hub::call('append', Hub::appendBody($this->node, 'u1'), $this->token)
        ->assertOk()->assertJsonPath('persisted', true)->assertJsonPath('permissions_stale', false);

    expect(Compaction::seqs($this->node))->toBe([1]);
});

it('refuses the append of a writer whose inherited Edit was revoked while View stayed: 403', function (): void {
    AccessTestHelpers::grant($this->node, RoleRegistry::READER);
    $this->drive->access()->revoke($this->folder->id, $this->grant);

    Hub::call('append', Hub::appendBody($this->node, 'u1'), $this->token)
        ->assertForbidden()->assertJsonPath('code', 'permission_denied');

    expect(Compaction::seqs($this->node))->toBe([]);
});

it('answers a writer who lost every right as if the document did not exist: 404', function (): void {
    $this->drive->access()->revoke($this->folder->id, $this->grant);

    Hub::call('append', Hub::appendBody($this->node, 'u1'), $this->token)
        ->assertNotFound()->assertJsonPath('code', 'node_not_found');

    expect(Compaction::seqs($this->node))->toBe([]);
});

it('refuses the append after the document moved into a subtree the writer has no rights in', function (): void {
    $elsewhere = $this->drive->folders()->create('Elsewhere');
    $this->drive->nodes()->move((string) $this->node->getKey(), $elsewhere->id);

    Hub::call('append', Hub::appendBody($this->node, 'u1'), $this->token)->assertNotFound();

    expect(Compaction::seqs($this->node))->toBe([]);
});

it('refuses the append after the document stopped inheriting the folder\'s grants', function (): void {
    $this->drive->access()->restrictInheritance((string) $this->node->getKey());

    Hub::call('append', Hub::appendBody($this->node, 'u1'), $this->token)->assertNotFound();
});

it('refuses the append once the drive is archived', function (): void {
    $this->drive->archive();

    Hub::call('append', Hub::appendBody($this->node, 'u1'), $this->token)->assertForbidden();
});

it('appends with a stale token while the rights hold, and tells the hub it is stale', function (): void {
    $this->drive->access()->grant($this->folder->id, new GrantInput(subject: Revocation::subject(7), role: RoleRegistry::READER));

    Hub::call('append', Hub::appendBody($this->node, 'u1'), $this->token)
        ->assertOk()->assertJsonPath('permissions_stale', true);
    Hub::call('append', Hub::appendBody($this->node, 'u2'), Hub::token($this->node))
        ->assertOk()->assertJsonPath('permissions_stale', false);

    expect(Compaction::seqs($this->node))->toBe([1, 2]);
});

it('marks every token stale after a global host change', function (): void {
    app(AccessInvalidation::class)->changed(null, 'host-role');

    Hub::call('append', Hub::appendBody($this->node, 'u1'), $this->token)
        ->assertOk()->assertJsonPath('permissions_stale', true);
});

it('checks the rights even when the versions say nothing changed', function (): void {
    // A host change the drive was never told about: no event, no version, the token looks current.
    NodeGrant::query()->whereKey($this->grant->id)->toBase()->delete();

    Hub::call('append', Hub::appendBody($this->node, 'u1'), Hub::token($this->node))->assertNotFound();

    expect(Compaction::seqs($this->node))->toBe([]);
});
