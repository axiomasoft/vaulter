<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
final readonly class ListingConfig
{
    public function __construct(private Repository $config) {}

    public function scanBudget(): int
    {
        $budget = $this->config->get('vaulter.listing.scan_budget', 1000);

        return is_numeric($budget) ? max(1, (int) $budget) : 1000;
    }
}
