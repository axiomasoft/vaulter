<?php

declare(strict_types=1);

namespace Vaulter\Http\Support;

use Closure;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Http\Middleware\AssignCorrelationId;
use Vaulter\Models\Drive\Drive;
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
        $handle = $this->forDrive($request, $this->resolver->drive($driveId));
        try {
            return $call($handle);
        } catch (NodeNotFoundException) {
            throw StorageNotFoundException::forId($driveId);
        }
    }

    public function user(Request $request): Authorizable
    {
        $user = $request->user();

        return $user instanceof Authorizable ? $user : throw MissingActorException::required();
    }
}
