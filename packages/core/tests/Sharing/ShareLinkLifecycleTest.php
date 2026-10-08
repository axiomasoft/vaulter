<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Data\Sharing\ShareLinkUpdate;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Sharing\ShareAudience;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Sharing\ShareLinkTtlExceededException;
use Vaulter\Exceptions\Sharing\ShareRoleNotAllowedException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Operations\OperationContext;
use Vaulter\Operations\ShareOperations;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Sharing\ShareResolution;
use Vaulter\Sharing\ShareResolver;
use Vaulter\Sharing\ShareSession;
use Vaulter\Sharing\ShareTokenMinter;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\Tests\Tenancy\MutableTenantResolver;

/**
 * The share link lifecycle of PLAN2.D47 (P4-I22): create → open → rotate/update/revoke,
 * with the redemption counted once per link session, the audience and the role rules
 * and the drive policy's TTL applied on every visit.
 */
beforeEach(function (): void {
    config()->set('vaulter.features.share_links', true);
    $this->drive = AccessTestHelpers::storage();
    $this->node = AccessTestHelpers::folder($this->drive, null, 'lifecycle');
    $this->shares = fn (): ShareOperations => app()->makeWith(ShareOperations::class, ['context' => OperationContext::system($this->drive, 'lifecycle fixture')]);
});

/** Replace the default profile with one carrying a share link TTL limit. */
function shareLimitProfile(int $days): void
{
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default')->shareLinks()->drivePolicy(static fn ($policy) => $policy->maxShareLinkTtlDays($days)));
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);
}

it('counts the redemption once per link session however many preview requests follow', function (): void {
    $minted = ($this->shares)()->create($this->node, new ShareLinkInput);

    $first = $this->getJson('api/vaulter/share/'.$minted->token)->assertOk();
    $session = $first->headers->get(ShareSession::HEADER);
    expect($session)->toBeString()->not->toBe('');

    $this->getJson('api/vaulter/share/'.$minted->token, [ShareSession::HEADER => $session])->assertOk();
    $this->getJson('api/vaulter/share/'.$minted->token.'/nodes', [ShareSession::HEADER => $session]);
    $this->getJson('api/vaulter/share/'.$minted->token, [ShareSession::HEADER => $session])->assertOk();

    expect($minted->share->refresh()->use_count)->toBe(1);

    // Without the session every request is a new visit.
    $this->getJson('api/vaulter/share/'.$minted->token)->assertOk();
    expect($minted->share->refresh()->use_count)->toBe(2);
});

it('lets a visit that already redeemed keep working once max_uses is reached, and refuses new visits', function (): void {
    $minted = ($this->shares)()->create($this->node, new ShareLinkInput(maxUses: 1));

    $session = $this->getJson('api/vaulter/share/'.$minted->token)->assertOk()->headers->get(ShareSession::HEADER);
    $this->getJson('api/vaulter/share/'.$minted->token, [ShareSession::HEADER => $session])->assertOk();
    $this->getJson('api/vaulter/share/'.$minted->token)->assertNotFound();

    expect($minted->share->refresh()->use_count)->toBe(1);
});

it('ignores a forged, expired or foreign session', function (): void {
    $one = ($this->shares)()->create($this->node, new ShareLinkInput);
    $two = ($this->shares)()->create($this->node, new ShareLinkInput);
    $sessions = app(ShareSession::class);
    $valid = $sessions->issue($one->share->refresh(), Carbon::now());

    expect($sessions->valid($valid, $one->share, Carbon::now()))->toBeTrue()
        ->and($sessions->valid($valid, $two->share, Carbon::now()))->toBeFalse()
        ->and($sessions->valid($valid.'x', $one->share, Carbon::now()))->toBeFalse()
        ->and($sessions->valid('forged.value', $one->share, Carbon::now()))->toBeFalse()
        ->and($sessions->valid($valid, $one->share, Carbon::now()->addHours(2)))->toBeFalse();
});

it('rotates the token: the old one is a 404, the new one works, sessions of the old one are void', function (): void {
    $minted = ($this->shares)()->create($this->node, new ShareLinkInput(maxUses: 5));
    $old = $minted->token;
    $session = $this->getJson('api/vaulter/share/'.$old)->assertOk()->headers->get(ShareSession::HEADER);

    $rotated = ($this->shares)()->rotate($this->node, $minted->share);

    expect($rotated->token)->not->toBe($old)
        ->and($rotated->share->token_generation)->toBe(2)
        ->and($rotated->share->token_hash)->toBe(hash('sha256', $rotated->token))
        ->and($rotated->share->token_prefix)->toBe(substr($rotated->token, 0, 8))
        ->and($rotated->share->use_count)->toBe(1);

    $this->getJson('api/vaulter/share/'.$old, [ShareSession::HEADER => $session])->assertNotFound();
    // The old session names generation 1; on the new token it buys nothing.
    $this->getJson('api/vaulter/share/'.$rotated->token, [ShareSession::HEADER => $session])->assertOk();
    expect($rotated->share->refresh()->use_count)->toBe(2);
});

it('refuses to rotate a revoked link', function (): void {
    $minted = ($this->shares)()->create($this->node, new ShareLinkInput);
    ($this->shares)()->revoke($this->node, $minted->share);

    expect(fn () => ($this->shares)()->rotate($this->node, $minted->share))->toThrow(NodeNotFoundException::class);
});

it('updates password, expiry, budget and audience without changing the token', function (): void {
    $minted = ($this->shares)()->create($this->node, new ShareLinkInput);
    $hash = $minted->share->token_hash;

    $updated = ($this->shares)()->update($this->node, $minted->share, new ShareLinkUpdate(
        expiresAt: now()->addDay(), password: 'open-sesame', maxUses: 3, audience: ShareAudience::Authenticated,
    ));

    expect($updated->token_hash)->toBe($hash)
        ->and($updated->token_generation)->toBe(1)
        ->and(Hash::check('open-sesame', $updated->password_hash))->toBeTrue()
        ->and($updated->max_uses)->toBe(3)
        ->and($updated->audience)->toBe(ShareAudience::Authenticated);

    // The same token now asks to sign in, then for the password.
    expect(app(ShareResolver::class)->resolve($minted->token)->resolution)->toBe(ShareResolution::LoginRequired);
    $actor = (new TestUser)->forceFill(['id' => 5]);
    expect(app(ShareResolver::class)->resolve($minted->token, actor: $actor)->resolution)->toBe(ShareResolution::PasswordRequired)
        ->and(app(ShareResolver::class)->resolve($minted->token, actor: $actor, password: 'open-sesame')->isOk())->toBeTrue();

    $cleared = ($this->shares)()->update($this->node, $minted->share, new ShareLinkUpdate(removePassword: true, removeMaxUses: true));
    expect($cleared->password_hash)->toBeNull()->and($cleared->max_uses)->toBeNull();
});

it('voids the session of a link when its password changes', function (): void {
    $minted = ($this->shares)()->create($this->node, new ShareLinkInput);
    $sessions = app(ShareSession::class);
    $session = $sessions->issue($minted->share->refresh(), Carbon::now());

    $updated = ($this->shares)()->update($this->node, $minted->share, new ShareLinkUpdate(password: 'new-secret'));

    expect($sessions->valid($session, $updated, Carbon::now()))->toBeFalse();
});

it('does not let update change what a link may carry', function (): void {
    $writer = ($this->shares)()->create($this->node, new ShareLinkInput(role: RoleRegistry::WRITER, audience: ShareAudience::Authenticated));

    expect(fn () => ($this->shares)()->update($this->node, $writer->share, new ShareLinkUpdate(audience: ShareAudience::Anyone)))
        ->toThrow(ShareRoleNotAllowedException::class)
        ->and($writer->share->refresh()->audience)->toBe(ShareAudience::Authenticated);
});

it('refuses a previewer link the original while it shows the node', function (): void {
    $file = Vaulter::drive(DriveTestHelpers::host())->asSystem('fixture')->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('bytes'), 'p.txt'));
    $preview = app()->makeWith(ShareOperations::class, ['context' => OperationContext::system($file->storage()->firstOrFail(), 'fixture')])
        ->create($file, new ShareLinkInput(role: RoleRegistry::PREVIEWER));

    $this->getJson('api/vaulter/share/'.$preview->token)->assertOk();
    $this->getJson('api/vaulter/share/'.$preview->token.'/download')->assertForbidden()->assertJsonPath('code', 'permission_denied');
});

it('keeps a foreign tenant out of a tenant link with an opaque 404', function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    $resolver = new MutableTenantResolver;
    app()->instance(TenantResolver::class, $resolver);
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($drive, null, 'tenant-only');
    $minted = app()->makeWith(ShareOperations::class, ['context' => OperationContext::system($drive, 'fixture')])
        ->create($node, new ShareLinkInput(audience: ShareAudience::Tenant));
    $actor = (new TestUser)->forceFill(['id' => 9]);
    $resolve = fn (?TestUser $who) => app(ShareResolver::class)->resolve($minted->token, actor: $who)->resolution;

    expect($resolve(null))->toBe(ShareResolution::LoginRequired)
        ->and($resolve($actor))->toBe(ShareResolution::Ok);

    $resolver->tenant = 'B';
    expect($resolve($actor))->toBe(ShareResolution::NotFound);
    $resolver->tenant = null;
    expect($resolve($actor))->toBe(ShareResolution::NotFound);

    MutableTenantResolver::clearFixtureStamps();
});

it('caps a link at the drive policy and applies a tightened policy to existing links at once', function (): void {
    shareLimitProfile(7);

    expect(fn () => ($this->shares)()->create($this->node, new ShareLinkInput(expiresAt: now()->addDays(10))))
        ->toThrow(ShareLinkTtlExceededException::class);

    $short = ($this->shares)()->create($this->node, new ShareLinkInput(expiresAt: now()->addDays(6)));
    $default = ($this->shares)()->create($this->node, new ShareLinkInput);
    expect($default->share->expires_at->lte(now()->addDays(7)->addMinute()))->toBeTrue();

    $this->getJson('api/vaulter/share/'.$short->token)->assertOk();

    // A long-lived link made before the policy was tightened stops resolving now.
    shareLimitProfile(1);
    $this->getJson('api/vaulter/share/'.$short->token)->assertNotFound();
    expect(fn () => ($this->shares)()->update($this->node, $short->share, new ShareLinkUpdate(expiresAt: now()->addDays(3))))
        ->toThrow(ShareLinkTtlExceededException::class);

    ($this->shares)()->update($this->node, $short->share, new ShareLinkUpdate(expiresAt: now()->addHours(12)));
    $this->getJson('api/vaulter/share/'.$short->token)->assertOk();
});

it('never writes tokens or passwords into the activity feed or the outbox', function (): void {
    $minted = ($this->shares)()->create($this->node, new ShareLinkInput(password: 'hunter2-secret'));
    ($this->shares)()->update($this->node, $minted->share, new ShareLinkUpdate(password: 'another-secret'));
    $rotated = ($this->shares)()->rotate($this->node, $minted->share);

    $dump = json_encode([
        Activity::query()->get()->toArray(),
        Outbox::query()->get()->toArray(),
    ], JSON_THROW_ON_ERROR);

    foreach ([$minted->token, $rotated->token, 'hunter2-secret', 'another-secret', hash('sha256', $minted->token), hash('sha256', $rotated->token)] as $secret) {
        expect($dump)->not->toContain($secret);
    }
    expect(NodeShare::query()->count())->toBe(1);
});

it('maps the share exceptions to their stable problem code and status', function (): void {
    $role = ShareRoleNotAllowedException::for('writer', ShareAudience::Anyone);
    $ttl = ShareLinkTtlExceededException::forLimit(7);

    expect([$role->problemCode(), $role->httpStatus()])->toBe(['share_role_not_allowed', 422])
        ->and([$ttl->problemCode(), $ttl->httpStatus()])->toBe(['share_link_ttl_exceeded', 422])
        ->and($role->getMessage())->toContain('docs/guide/errors.md#share-role-not-allowed')
        ->and($ttl->getMessage())->toContain('docs/guide/errors.md#share-link-ttl-exceeded');
});

it('retries a token collision inside its own savepoint so the caller transaction survives', function (): void {
    $database = app(VaulterDatabase::class);
    $levels = [];
    $attempts = 0;
    $outer = 0;

    [$result, $token] = $database->transaction(function () use ($database, &$levels, &$attempts, &$outer) {
        $outer = $database->connection()->transactionLevel();

        return app(ShareTokenMinter::class)->mint(function (string $hash) use ($database, &$levels, &$attempts): string {
            $levels[] = $database->connection()->transactionLevel();
            if (++$attempts === 1) {
                throw new UniqueConstraintViolationException('sqlite', 'insert', [], new PDOException('UNIQUE', 23000));
            }

            return $hash;
        });
    });

    expect($attempts)->toBe(2)
        ->and($result)->toBe(hash('sha256', $token))
        // Each attempt runs one level (a savepoint) below the caller's transaction.
        ->and($levels)->toBe([$outer + 1, $outer + 1]);
});
