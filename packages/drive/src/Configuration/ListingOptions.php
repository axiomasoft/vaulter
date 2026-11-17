<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled listing options for `vaulter-drive.listing.*`.
 */
#[Api(since: '1.0')]
final readonly class ListingOptions
{
    public function __construct(
        public int $scanBudget = 1000,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['scan_budget'], $path);

        $budget = $raw['scan_budget'] ?? 1000;

        return new self(
            scanBudget: is_numeric($budget) ? max(1, (int) $budget) : 1000,
        );
    }
}
