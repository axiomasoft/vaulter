<?php

declare(strict_types=1);

namespace Vaulter\Idempotency;

use InvalidArgumentException;

/**
 * A neutral reference to the resource an idempotency receipt is about, or to the authorization scope it was
 * granted in (D25): a kind of the owning module (`node`, `drive`) and the string id. The kernel stores and
 * returns it; only the owner's {@see ReplayAuthorizer} resolves it.
 */
final readonly class ResourceRef
{
    public function __construct(
        public string $kind,
        public string $id,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,31}$/', $kind) !== 1) {
            throw new InvalidArgumentException('[Vaulter] A resource kind must be a lowercase identifier of at most 32 bytes.');
        }
        if ($id === '' || strlen($id) > 191) {
            throw new InvalidArgumentException('[Vaulter] A resource id must be nonempty and at most 191 bytes.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->id === $other->id;
    }
}
