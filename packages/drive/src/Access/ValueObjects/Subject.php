<?php

declare(strict_types=1);

namespace Vaulter\Drive\Access\ValueObjects;

use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Api;

/**
 * A grant subject — the (type, id) pair a v_node_grants row is addressed to
 * . The type is a registered morph alias (user|role|team
 * or a host alias), never a raw FQCN leaking through the API; the PermissionDriver
 * expands an actor into the list of Subjects whose grants apply (user + roles +
 * teams) and Vaulter builds `WHERE (subject_type, subject_id) IN (...)` from them.
 *
 * Final readonly VO — value equality, no behaviour beyond identity helpers.
 */
#[Api]
final readonly class Subject
{
    public function __construct(
        public string $type,
        public int|string $id,
    ) {}

    public static function make(string $type, int|string $id): self
    {
        return new self(type: $type, id: $id);
    }

    /**
     * Build a Subject from an Eloquent model, using its registered morph alias so
     * the stored subject_type is alias-safe (the DatabasePermissionDriver's
     * subjectsFor() lane).
     */
    public static function ofModel(Model $model): self
    {
        /** @var int|string $id */
        $id = $model->getKey();

        return new self(type: $model->getMorphClass(), id: $id);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type
            && (string) $this->id === (string) $other->id;
    }

    /**
     * A stable string form for keying/deduping a subject-set.
     */
    public function signature(): string
    {
        return $this->type.':'.$this->id;
    }
}
