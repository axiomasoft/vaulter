<?php

declare(strict_types=1);

namespace Vaulter\Panels;

/**
 * Resolves a physical table name for a (panel, base entity) pair by delegating
 * to the panel's own resolution. A thin seam over PanelRegistry so callers
 * (migrations, models) need not hold a Panel instance.
 */
final readonly class PanelTableResolver
{
    public function __construct(
        private PanelRegistry $registry,
    ) {}

    public function table(string $panel, string $base): string
    {
        return $this->registry->get($panel)->table($base);
    }
}
