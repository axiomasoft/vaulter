<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Vaulter\Collaboration\Exceptions\CollaborationEpochMismatchException;
use Vaulter\Collaboration\Persistence\UpdateEnvelope;
use Vaulter\Collaboration\Persistence\UpdateLog;
use Vaulter\Collaboration\Tests\Collaboration\HubTestHelpers as Hub;
use Vaulter\Collaboration\Tests\Support\DocumentTestHelpers;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Data\Revisions\RestoreDocumentRevisionInput;
use Vaulter\Documents\Exceptions\DocumentStatePendingException;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Drive\Models\Node;

/*
 * Scenario 7 (C4): only the document's current generation takes appends. A restore between the hub's load and its
 * append moves the generation on, and the append of the old one is refused with collaboration_epoch_mismatch and
 * writes nothing; a retired generation refuses every append. A restore never closes a generation over saved edits it
 * has not checkpointed (document_state_pending, C8).
 */

function fenceAppend(Node $node, int $generation, string $payload = 'update'): mixed
{
    return app(UpdateLog::class)->append($node->getKey(), new UpdateEnvelope(
        generation: $generation,
        updateId: (string) Str::uuid(),
        payload: $payload,
        claimedSha256: hash('sha256', $payload),
    ));
}

function fenceRestore(Node $node): void
{
    $first = DocumentRevision::query()->where('document_id', Hub::aggregate($node)->getKey())->orderBy('number')->firstOrFail();
    app(RestoreDocumentRevisionAction::class)->execute(DocumentTestHelpers::context($node, actorId: 1), new RestoreDocumentRevisionInput(node: $node, revision: $first));
}

function fenceRows(string $entity): int
{
    return DB::table(app(DatabaseConfig::class)->table($entity))->count();
}

beforeEach(function (): void {
    Hub::configure();
    $this->node = Hub::document();
});

it('refuses an append of the generation a restore closed between load and append', function (): void {
    // The hub loaded generation 0; a restore commits before its first append.
    $loaded = Hub::aggregate($this->node)->collab_epoch;
    fenceRestore($this->node);

    expect(fn () => fenceAppend($this->node, $loaded))->toThrow(CollaborationEpochMismatchException::class)
        ->and(Hub::aggregate($this->node)->collab_epoch)->toBe($loaded + 1)
        ->and(fenceRows('collaboration_updates'))->toBe(0)
        ->and(fenceRows('collaboration_receipts'))->toBe(0)
        ->and(Hub::aggregate($this->node)->pending_generation)->toBeNull();
});

it('answers the hub the same 409 over HTTP for a room of the closed generation', function (): void {
    $token = Hub::token($this->node);
    $body = Hub::appendBody($this->node, 'update');
    fenceRestore($this->node);

    Hub::callV2('append', $body, $token)
        ->assertStatus(409)
        ->assertJsonPath('code', 'collaboration_epoch_mismatch');
    expect(fenceRows('collaboration_updates'))->toBe(0);
});

it('takes appends of the new generation after the restore, numbered from 1 again', function (): void {
    fenceAppend($this->node, 0);
    // The saved edit of generation 0 is not checkpointed: the restore is refused and generation 0 keeps it.
    expect(fn () => fenceRestore($this->node))->toThrow(DocumentStatePendingException::class)
        ->and(Hub::aggregate($this->node)->collab_epoch)->toBe(0)
        ->and(fenceRows('collaboration_receipts'))->toBe(1);

    // Once the generation is clear (as a checkpoint would leave it), the restore closes it and the next one starts at 1.
    Hub::aggregate($this->node)->forceFill(['pending_generation' => null, 'pending_through_seq' => null, 'pending_protocol' => null, 'pending_state_format' => null, 'pending_schema_version' => null])->save();
    fenceRestore($this->node);
    $result = fenceAppend($this->node, 1);

    expect($result->generation)->toBe(1)
        ->and($result->seq)->toBe(1)
        ->and(fenceRows('collaboration_heads'))->toBe(2);
});

it('refuses every append of a retired generation', function (): void {
    fenceAppend($this->node, 0);
    DB::table(app(DatabaseConfig::class)->table('collaboration_heads'))->update(['retired_at' => now()]);

    expect(fn () => fenceAppend($this->node, 0, 'late'))->toThrow(CollaborationEpochMismatchException::class)
        ->and(fenceRows('collaboration_updates'))->toBe(1);
});

it('refuses an append of a generation the document never reached', function (): void {
    expect(fn () => fenceAppend($this->node, 5))->toThrow(CollaborationEpochMismatchException::class)
        ->and(fenceRows('collaboration_heads'))->toBe(0);
});
