<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Support;

use Closure;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Vaulter;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Http\Middleware\AssignCorrelationId;
use Vaulter\Http\Middleware\ReadIdempotencyKey;
use Vaulter\Operations\OperationContext;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\ValueObjects\Operations\ScopeRef;
use Vaulter\ValueObjects\Operations\TenantRef;

/**
 * The one way a controller of the versioned API reaches the operations: the drive
 * handle acting as the request's user, carrying the request's correlation id. The
 * HTTP layer therefore authorizes exactly as a PHP caller of the same operation does.
 */
#[Internal]
final readonly class HttpOperationContextFactory
{
    public function __construct(
        private Vaulter $vaulter,
        private HttpResourceResolver $resolver,
        private AccessResolver $access,
    ) {}

    public function forDrive(Request $request, Drive $drive): DriveHandle
    {
        $handle = $this->vaulter->drive($drive)
            ->actingAs($this->user($request))
            ->withCorrelationId($this->correlationId($request));
        // Only the routes of idempotent operations read the key (ReadIdempotencyKey); elsewhere it is ignored.
        $key = ReadIdempotencyKey::key($request);

        return $key === null ? $handle : $handle->withIdempotencyKey($key, ReadIdempotencyKey::requestFingerprint($request));
    }

    /** The neutral context of a request acting within a scope that is not a drive. */
    public function operation(Request $request, ScopeRef $scope, TenantRef $tenant): OperationContext
    {
        $key = ReadIdempotencyKey::key($request);

        return OperationContext::for(
            actor: Actor::user($this->user($request)),
            scope: $scope,
            tenant: $tenant,
            correlationId: $this->correlationId($request),
            idempotencyKey: $key,
            requestFingerprint: $key === null ? null : ReadIdempotencyKey::requestFingerprint($request),
        );
    }

    /** The context of a request acting on one drive, as the drive handle builds it. */
    public function driveContext(Request $request, Drive $drive): DriveContext
    {
        $key = ReadIdempotencyKey::key($request);

        return DriveContext::for(
            drive: $drive,
            actor: Actor::user($this->user($request)),
            correlationId: $this->correlationId($request),
            idempotencyKey: $key,
            requestFingerprint: $key === null ? null : ReadIdempotencyKey::requestFingerprint($request),
        );
    }

    /**
     * Run a drive-level call ({drive} in the URL), mapping the operation's missing
     * resource to drive_not_found. Drive-wide listings use onVisibleDrive() to admit
     * the drive before running the operation.
     *
     * @template T
     *
     * @param  Closure(DriveHandle): T $call
     * @return T
     */
    public function onDrive(Request $request, string $driveId, Closure $call): mixed
    {
        try {
            // Opening the handle re-reads the drive's state, so a drive that started
            // being deleted since it was resolved is not found here either.
            return $call($this->forDrive($request, $this->resolver->drive($driveId)));
        } catch (NodeNotFoundException) {
            throw StorageNotFoundException::forId($driveId);
        }
    }

    /**
     * A drive-wide listing requires at least one viewable node. A grant on a nested
     * folder is sufficient; an entirely invisible drive answers exactly like an unknown
     * drive before any listing, cursor or mutation is run.
     *
     * Below an invisible root only a node holding one of the actor's grants can be
     * visible (a grant reaches its own node whatever its scope, and the profile hides a
     * whole drive or nothing), so checking those anchors is exact and costs the same
     * for a stranger however large the drive is.
     *
     * @template T
     *
     * @param  Closure(DriveHandle): T $call
     * @return T
     */
    public function onVisibleDrive(Request $request, string $driveId, Closure $call): mixed
    {
        return $this->onDrive($request, $driveId, function (DriveHandle $handle) use ($call): mixed {
            if (!$this->seesAnyNode($handle)) {
                throw NodeNotFoundException::forId('drive');
            }

            return $call($handle);
        });
    }

    private function seesAnyNode(DriveHandle $handle): bool
    {
        try {
            $handle->root();

            return true;
        } catch (NodeNotFoundException) {
            // A grant below the root can still make this drive readable.
        }
        $actor = $handle->actor()->authorizable();
        $anchors = $actor === null ? [] : $this->access->grantAnchorIdsIn($actor, $handle->model());
        foreach (array_chunk($anchors, 200) as $chunk) {
            $ids = array_map(static fn (int|string $id): string => Identity::stringify($id), $chunk);
            foreach ($handle->nodes()->abilitiesMany($ids) as $abilities) {
                if ($abilities->flags[Ability::View->value] ?? false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Run a call addressed by a drive id that may also name a node inside it (a parent
     * folder, a target file). When something inside is not found and the caller cannot
     * see the drive's root either, the answer is drive_not_found — the same as for an
     * unknown drive — so a child id never reveals that an invisible drive exists. A
     * caller who sees the drive gets node_not_found for the missing node.
     *
     * @template T
     *
     * @param  Closure(DriveHandle): T $call
     * @return T
     */
    public function withinDrive(Request $request, string $driveId, Closure $call): mixed
    {
        $drive = null;
        try {
            $drive = $this->forDrive($request, $this->resolver->drive($driveId));

            return $call($drive);
        } catch (NodeNotFoundException $missing) {
            if ($drive === null) {
                throw StorageNotFoundException::forId($driveId);
            }
            try {
                $drive->root();
            } catch (NodeNotFoundException) {
                throw StorageNotFoundException::forId($driveId);
            }

            throw $missing;
        }
    }

    private function correlationId(Request $request): string
    {
        return AssignCorrelationId::of($request) ?? (string) Str::ulid();
    }

    public function user(Request $request): Authorizable
    {
        $user = $request->user();

        return $user instanceof Authorizable ? $user : throw MissingActorException::required();
    }
}
