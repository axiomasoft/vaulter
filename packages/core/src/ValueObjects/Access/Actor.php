<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Api;

#[Api]
final readonly class Actor
{
    private function __construct(
        private ?Authorizable $user,
        private ActorRef $reference,
    ) {}

    public static function user(Authorizable $user): self
    {
        [$type, $id] = self::identityOf($user);

        if ((!is_int($id) && !is_string($id)) || $id === '' || $type === ActorRef::SYSTEM_TYPE) {
            throw new InvalidArgumentException('[Vaulter] A user actor requires a stable user ID and a non-system type.');
        }

        return new self(user: $user, reference: new ActorRef(type: $type, id: $id));
    }

    public static function system(string $reason): self
    {
        return new self(user: null, reference: new ActorRef(type: ActorRef::SYSTEM_TYPE, id: null, reason: $reason));
    }

    public function isSystem(): bool
    {
        return $this->reference->type === ActorRef::SYSTEM_TYPE;
    }

    public function authorizable(): ?Authorizable
    {
        if ($this->user === null) {
            return null;
        }
        [$type, $id] = self::identityOf($this->user);
        if ($type !== $this->reference->type || $id !== $this->reference->id) {
            throw new InvalidArgumentException('[Vaulter] The bound actor identity changed; create a new actor for the new identity.');
        }

        return $this->user;
    }

    public function ref(): ActorRef
    {
        return $this->reference;
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('[Vaulter] Serialize ActorRef and drive_id in jobs, then resolve current identity and permissions at execution.');
    }

    /** @return array{0: string, 1: mixed} */
    private static function identityOf(Authorizable $user): array
    {
        if ($user instanceof Model) {
            return self::modelIdentity($user);
        }
        if ($user instanceof Authenticatable) {
            return self::authenticatableIdentity($user);
        }

        throw new InvalidArgumentException('[Vaulter] An actor must provide a stable Eloquent or Authenticatable identity.');
    }

    /** @return array{0: string, 1: mixed} */
    private static function modelIdentity(Model $user): array
    {
        return [$user->getMorphClass(), $user->getKey()];
    }

    /** @return array{0: string, 1: mixed} */
    private static function authenticatableIdentity(Authenticatable $user): array
    {
        return [$user::class, $user->getAuthIdentifier()];
    }
}
