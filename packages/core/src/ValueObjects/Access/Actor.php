<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

final readonly class Actor
{
    private function __construct(
        private ?Authorizable $user,
        private ActorRef $reference,
    ) {}

    public static function user(Authorizable $user): self
    {
        if ($user instanceof Model) {
            $type = $user->getMorphClass();
            $id = $user->getKey();
        } elseif ($user instanceof Authenticatable) {
            $type = $user::class;
            $id = $user->getAuthIdentifier();
        } else {
            throw new InvalidArgumentException('[Vaulter] An actor must provide a stable Eloquent or Authenticatable identity.');
        }

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
        if ($this->user instanceof Model) {
            $type = $this->user->getMorphClass();
            $id = $this->user->getKey();
        } elseif ($this->user instanceof Authenticatable) {
            $type = $this->user::class;
            $id = $this->user->getAuthIdentifier();
        } else {
            return null;
        }
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
}
