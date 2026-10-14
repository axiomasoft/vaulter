<?php

declare(strict_types=1);

namespace Vaulter\Modules;

/**
 * One contribution to a slot, as the sealed registry lists it: ordered by `priority` ascending,
 * then `id`.
 */
final readonly class SlotContribution
{
    /**
     * @param class-string $slot
     * @param class-string $implementation
     */
    public function __construct(
        public string $slot,
        public string $id,
        public string $module,
        public string $implementation,
        public int $priority = 0,
        public ?string $key = null,
    ) {}
}
