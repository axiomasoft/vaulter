<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled feature flags for `vaulter-drive.features.*`.
 */
#[Api(since: '1.0')]
final readonly class DriveFeaturesOptions
{
    public function __construct(
        public bool $grants = true,
        public bool $activity = true,
        public bool $changes = true,
        public bool $shareLinks = false,
        public bool $publicListing = false,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['grants', 'activity', 'changes', 'share_links', 'public_listing'], $path);

        return new self(
            grants: ($raw['grants'] ?? true) === true,
            activity: ($raw['activity'] ?? true) === true,
            changes: ($raw['changes'] ?? true) === true,
            shareLinks: ($raw['share_links'] ?? false) === true,
            publicListing: ($raw['public_listing'] ?? false) === true,
        );
    }

    public function flag(string $feature): bool
    {
        return match ($feature) {
            'grants' => $this->grants,
            'activity' => $this->activity,
            'changes' => $this->changes,
            'share_links' => $this->shareLinks,
            'public_listing' => $this->publicListing,
            default => false,
        };
    }
}
