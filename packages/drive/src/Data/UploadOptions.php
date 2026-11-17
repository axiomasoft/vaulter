<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data;

use DateTimeInterface;
use Packman\Attributes\Api;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\OnNameConflict;

#[Api]
final readonly class UploadOptions
{
    public function __construct(
        public NodeSnapshot|string|int|null $parent = null,
        public ?string $name = null,
        public OnNameConflict $onConflict = OnNameConflict::Fail,
        public ?string $declaredMimeType = null,
        public ?DateTimeInterface $clientModifiedAt = null,
    ) {}

    public static function make(): self
    {
        return new self;
    }

    public function parent(NodeSnapshot|string|int|null $parent): self
    {
        return new self(parent: $parent, name: $this->name, onConflict: $this->onConflict, declaredMimeType: $this->declaredMimeType, clientModifiedAt: $this->clientModifiedAt);
    }

    public function name(?string $name): self
    {
        return new self(parent: $this->parent, name: $name, onConflict: $this->onConflict, declaredMimeType: $this->declaredMimeType, clientModifiedAt: $this->clientModifiedAt);
    }

    public function onConflict(OnNameConflict $onConflict): self
    {
        return new self(parent: $this->parent, name: $this->name, onConflict: $onConflict, declaredMimeType: $this->declaredMimeType, clientModifiedAt: $this->clientModifiedAt);
    }

    public function declaredMimeType(?string $declaredMimeType): self
    {
        return new self(parent: $this->parent, name: $this->name, onConflict: $this->onConflict, declaredMimeType: $declaredMimeType, clientModifiedAt: $this->clientModifiedAt);
    }

    /** The uploader's own modification time: stored for display, never trusted. */
    public function clientModifiedAt(?DateTimeInterface $clientModifiedAt): self
    {
        return new self(parent: $this->parent, name: $this->name, onConflict: $this->onConflict, declaredMimeType: $this->declaredMimeType, clientModifiedAt: $clientModifiedAt);
    }
}
