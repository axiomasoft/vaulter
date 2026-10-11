<?php

declare(strict_types=1);

namespace Vaulter\Http\Support;

use Closure;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Http\Middleware\AssignCorrelationId;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\VaulterManager;

/**
 * The one way a controller of the versioned API reaches the operations: the drive
 * handle acting as the request's user, carrying the request's correlation id. The
 * HTTP layer therefore authorizes exactly as a PHP caller of the same operation does.
 */
final readonly class HttpOperationContextFactory
{
    public function __construct(private VaulterManager $vaulter, private HttpResourceResolver $resolver) {}

    public function forDrive(Request $request, Drive $drive): DriveHandle
    {
        return $this->vaulter->drive($drive)
            ->actingAs($this->user($request))
            ->withCorrelationId(AssignCorrelationId::of($request) ?? (string) Str::ulid());
    }

    /**
     * Run a drive-level call ({drive} in the URL). A drive whose root the caller cannot
     * see answers drive_not_found like an unknown or foreign one, so the routes do not
     * reveal which drive ids exist.
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
     * A drive-wide listing requires at least one viewable node, including the trash.
     * A grant on a nested folder is sufficient; an entirely invisible drive answers
     * exactly like an unknown drive before any listing, cursor or mutation is run.
     *
     * @template T
     *
     * @param  Closure(DriveHandle): T $call
     * @return T
     */
    public function onVisibleDrive(Request $request, string $driveId, Closure $call): mixed
    {
        return $this->onDrive($request, $driveId, static function (DriveHandle $handle) use ($call): mixed {
            $visible = false;
            try {
                $handle->root();
                $visible = true;
            } catch (NodeNotFoundException) {
                // A nested or trashed grant can still make this drive readable.
            }
            if ($visible) {
                return $call($handle);
            }
            $handle->model()->nodes()->chunkById(200, static function (Collection $nodes) use ($handle, &$visible): bool {
                $ids = $nodes->map(static fn (Node $node): string => (string) $node->getKey())->all();
                foreach ($handle->nodes()->abilitiesMany($ids) as $abilities) {
                    if ($abilities->flags[Ability::View->value] ?? false) {
                        $visible = true;

                        return false;
                    }
                }

                return true;
            });
            if (!$visible) {
                throw NodeNotFoundException::forId('drive');
            }

            return $call($handle);
        });
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

    public function user(Request $request): Authorizable
    {
        $user = $request->user();

        return $user instanceof Authorizable ? $user : throw MissingActorException::required();
    }
}
