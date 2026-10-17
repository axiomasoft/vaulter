<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
#[Api]
final readonly class ListingConfig
{
    #[Internal]
    public function __construct(private Repository $config) {}

    public function scanBudget(): int
    {
        $budget = $this->config->get('vaulter-drive.listing.scan_budget', 1000);

        return is_numeric($budget) ? max(1, (int) $budget) : 1000;
    }
}
