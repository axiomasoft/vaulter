<?php

declare(strict_types=1);

namespace DocVault\ValueObjects;

/**
 * A polymorphic reference to the host entity that owns a set of documents
 * (organization, project, deal, user…).
 */
final readonly class WorkspaceRef
{
    public function __construct(
        public string $type,
        public int|string $id,
    ) {}

    public static function make(string $type, int|string $id): self
    {
        return new self(type: $type, id: $id);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type
            && (string) $this->id === (string) $other->id;
    }
}
