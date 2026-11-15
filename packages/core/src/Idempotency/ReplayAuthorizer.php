<?php

declare(strict_types=1);

namespace Vaulter\Idempotency;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/**
 * The owner's current authorization of a replay (architecture#operation-chains «Replay»): a receipt is
 * returned only to an actor who may still run the operation now. Called inside the operation's transaction
 * before the stored result is decoded; a denial is answered exactly as a new request would be, and the
 * receipt stays as it is.
 */
#[Spi(SpiKind::Implement)]
interface ReplayAuthorizer
{
    /**
     * The authorization scope recorded with a receipt: for a result, the nearest container it was authorized
     * in (a parent folder, the drive); for a cached failure (`$result` null), the scope of the request.
     */
    public function scope(mixed $result): ?ResourceRef;

    /**
     * Throws the owner's not-found or permission-denied refusal unless the actor may run the operation now:
     * on `$resource` where it is now when it still exists, else on the recorded `$scope` (its nearest
     * surviving container, then the drive).
     */
    public function authorize(?ResourceRef $resource, ?ResourceRef $scope): void;
}
