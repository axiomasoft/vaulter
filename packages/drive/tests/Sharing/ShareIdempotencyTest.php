<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Data\Sharing\ShareLinkUpdate;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Idempotency\ShareReceiptCodec;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Exceptions\Concurrency\StaleWriteException;
use Vaulter\Exceptions\Idempotency\IdempotencyKeyReusedException;
use Vaulter\Idempotency\IdempotencyStore;
use Vaulter\Idempotency\ReplayedProblemException;

/**
 * P6.3 (D25, D47(5), ID2, ID5): the share-link writes are receipt operations. A link created or rotated under an
 * Idempotency-Key is replayed with the same token — to the same actor only, after its current authorization — and the
 * receipt holds that token only as the application's ciphertext. Another actor under the same key runs a new
 * operation of its own (its scope digest differs); an actor who lost Share gets the refusal of a new request.
 */
beforeEach(function (): void {
    config()->set('vaulter-drive.features.share_links', true);
    $this->system = V1::drive(userId: 1);
    $this->owner = $this->system->actingAs(V1::user(1));
    $this->folder = V1::folder($this->system, 'shared');
    $this->keyed = fn (string $key, int $user = 1): DriveHandle => $this->system->actingAs(V1::user($user))->withIdempotencyKey($key);
});

/** @return list<string> every stored receipt body */
function shareReceipts(): array
{
    return array_values(array_map(static fn (mixed $body): string => (string) $body, app(VaulterDatabase::class)->connection()->table(app(IdempotencyStore::class)->table())->pluck('receipt_body')->all()));
}

function shareEvents(DriveEventType $type): int
{
    return OutboxEvent::query()->where('type', $type->value)->count();
}

it('replays a created link with the same token and keeps that token in the receipt only as ciphertext', function (): void {
    $first = ($this->keyed)('create-1')->shares()->create($this->folder->id, new ShareLinkInput(maxUses: 3));
    $again = ($this->keyed)('create-1')->shares()->create($this->folder->id, new ShareLinkInput(maxUses: 3));

    expect($again->token)->toBe($first->token)
        ->and($again->link->id)->toBe($first->link->id)
        ->and(NodeShare::query()->count())->toBe(1)
        ->and(shareEvents(DriveEventType::ShareCreated))->toBe(1);

    $receipts = shareReceipts();
    expect($receipts)->toHaveCount(1)->and($receipts[0])->not->toContain($first->token);
    $body = json_decode($receipts[0], true, flags: JSON_THROW_ON_ERROR);
    expect($body['type'])->toBe('minted')
        ->and(Crypt::decryptString($body['token']))->toBe($first->token);
});

it('replays a rotation with the same new token to the same actor, once', function (): void {
    $link = $this->owner->shares()->create($this->folder->id, new ShareLinkInput)->link;

    $first = ($this->keyed)('rotate-1')->shares()->rotate($this->folder->id, $link->id, $link->version);
    $again = ($this->keyed)('rotate-1')->shares()->rotate($this->folder->id, $link->id, $link->version);

    expect($again->token)->toBe($first->token)
        ->and($again->link->version)->toBe($link->version + 1)
        ->and(NodeShare::query()->findOrFail($link->id)->token_generation)->toBe(2)
        ->and(shareEvents(DriveEventType::ShareRotated))->toBe(1);
    foreach (shareReceipts() as $receipt) {
        expect($receipt)->not->toContain($first->token);
    }
});

it('runs a new operation for another actor under the same key and never hands it the first token', function (): void {
    AccessTestHelpers::grant(DriveTestHelpers::model($this->folder), RoleRegistry::OWNER, subjectId: 2);
    $link = $this->owner->shares()->create($this->folder->id, new ShareLinkInput)->link;
    ($this->keyed)('rotate-shared', 1)->shares()->rotate($this->folder->id, $link->id, $link->version);

    // The second actor's digest differs: its rotation under the version the first one consumed is stale.
    expect(fn () => ($this->keyed)('rotate-shared', 2)->shares()->rotate($this->folder->id, $link->id, $link->version))
        ->toThrow(StaleWriteException::class);
    // A stranger is refused before any receipt is looked at.
    expect(fn () => ($this->keyed)('rotate-shared', 3)->shares()->rotate($this->folder->id, $link->id, $link->version))
        ->toThrow(NodeNotFoundException::class);

    expect(NodeShare::query()->findOrFail($link->id)->token_generation)->toBe(2);
});

it('refuses the replay of a rotation to an actor who lost Share, without the token', function (): void {
    AccessTestHelpers::grant(DriveTestHelpers::model($this->folder), RoleRegistry::OWNER, subjectId: 2);
    $link = $this->owner->shares()->create($this->folder->id, new ShareLinkInput)->link;
    ($this->keyed)('rotate-by-2', 2)->shares()->rotate($this->folder->id, $link->id, $link->version);

    NodeGrant::query()->where('node_id', DriveTestHelpers::model($this->folder)->getKey())->where('subject_id', '2')->delete();

    expect(fn () => ($this->keyed)('rotate-by-2', 2)->shares()->rotate($this->folder->id, $link->id, $link->version))
        ->toThrow(NodeNotFoundException::class);
});

it('refuses another request under a used key', function (): void {
    $link = $this->owner->shares()->create($this->folder->id, new ShareLinkInput)->link;
    ($this->keyed)('update-1')->shares()->update($this->folder->id, $link->id, new ShareLinkUpdate(maxUses: 4), $link->version);

    expect(fn () => ($this->keyed)('update-1')->shares()->update($this->folder->id, $link->id, new ShareLinkUpdate(maxUses: 5), $link->version))
        ->toThrow(IdempotencyKeyReusedException::class);
    expect(fn () => ($this->keyed)('update-1')->shares()->update($this->folder->id, $link->id, new ShareLinkUpdate(password: 'other'), $link->version))
        ->toThrow(IdempotencyKeyReusedException::class);

    expect(NodeShare::query()->findOrFail($link->id)->max_uses)->toBe(4);
});

it('replays an update and a revocation once each and stores a stale refusal under its key', function (): void {
    $link = $this->owner->shares()->create($this->folder->id, new ShareLinkInput)->link;

    $updated = ($this->keyed)('update-2')->shares()->update($this->folder->id, $link->id, new ShareLinkUpdate(maxUses: 4), $link->version);
    expect(($this->keyed)('update-2')->shares()->update($this->folder->id, $link->id, new ShareLinkUpdate(maxUses: 4), $link->version))->toEqual($updated)
        ->and(shareEvents(DriveEventType::ShareUpdated))->toBe(1);

    // A stale write is a deterministic refusal: its receipt answers the retry the same way.
    expect(fn () => ($this->keyed)('stale-1')->shares()->update($this->folder->id, $link->id, new ShareLinkUpdate(maxUses: 6), $link->version))->toThrow(StaleWriteException::class);
    try {
        ($this->keyed)('stale-1')->shares()->update($this->folder->id, $link->id, new ShareLinkUpdate(maxUses: 6), $link->version);
        $this->fail('The stored refusal was not replayed.');
    } catch (ReplayedProblemException $replayed) {
        expect($replayed->problemCode())->toBe('stale_write')->and($replayed->httpStatus())->toBe(412);
    }

    ($this->keyed)('revoke-1')->shares()->revoke($this->folder->id, $link->id, $updated->version);
    ($this->keyed)('revoke-1')->shares()->revoke($this->folder->id, $link->id, $updated->version);
    expect(shareEvents(DriveEventType::ShareRevoked))->toBe(1)
        ->and(NodeShare::query()->findOrFail($link->id)->version)->toBe($updated->version + 1)
        ->and(NodeShare::query()->findOrFail($link->id)->max_uses)->toBe(4);
});

it('decodes only the version it knows and never encodes a link with its token in clear', function (): void {
    $codec = new ShareReceiptCodec(app('encrypter'));
    $minted = $this->owner->shares()->create($this->folder->id, new ShareLinkInput);
    $body = $codec->encode($minted);

    expect(json_encode($body, JSON_THROW_ON_ERROR))->not->toContain($minted->token)
        ->and($codec->decode($body, 1))->toEqual($minted)
        ->and(fn () => $codec->decode($body, 2))->toThrow(LogicException::class);
});

it('keeps a node snapshot type out of the share codec', function (): void {
    $codec = new ShareReceiptCodec(app('encrypter'));

    expect(fn () => $codec->encode($this->owner->root()))->toThrow(InvalidArgumentException::class)
        ->and($this->owner->root())->toBeInstanceOf(NodeSnapshot::class);
});
