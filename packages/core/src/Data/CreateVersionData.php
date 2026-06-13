<?php

declare(strict_types=1);

namespace DocVault\Data;

final readonly class CreateVersionData
{
    public function __construct(
        public ?string $snapshotLabel = null,
        public int|string|null $actorId = null,
    ) {}
}
