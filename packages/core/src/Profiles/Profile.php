<?php

declare(strict_types=1);

namespace Vaulter\Profiles;

/** A named policy for drives sharing the configured Vaulter schema. */
final class Profile
{
    /** @param array<string, bool> $features */
    public function __construct(
        private string $id = '',
        private ?string $hostModel = null,
        private bool $hasGrants = true,
        private array $features = [],
    ) {}

    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function host(string $model): self
    {
        $this->hostModel = $model;

        return $this;
    }

    public function grants(): self
    {
        $this->hasGrants = true;

        return $this;
    }

    public function withoutGrants(): self
    {
        $this->hasGrants = false;

        return $this;
    }

    /** @param array<string, bool> $flags */
    public function features(array $flags): self
    {
        $this->features = [...$this->features, ...$flags];

        return $this;
    }

    public function feature(string $name, bool $on = true): self
    {
        $this->features[$name] = $on;

        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function hostModel(): ?string
    {
        return $this->hostModel;
    }

    public function hasGrants(): bool
    {
        return $this->hasGrants;
    }

    public function featureEnabled(string $name): bool
    {
        if (array_key_exists($name, $this->features)) {
            return $this->features[$name];
        }

        return match ($name) {
            'grants' => $this->hasGrants,
            'versions', 'blobs', 'tags', 'activity', 'uploads', 'extra_data', 'webhooks' => true,
            'share_links', 'public_listing', 'document_versions' => false,
            default => false,
        };
    }
}
