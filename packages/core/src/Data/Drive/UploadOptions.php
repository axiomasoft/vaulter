<?php

declare(strict_types=1);

namespace Vaulter\Data\Drive;

use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Models\Drive\Node;

final readonly class UploadOptions
{
    public function __construct(
        public Node|int|string|null $parent = null,
        public ?string $name = null,
        public OnNameConflict $onConflict = OnNameConflict::Fail,
        public ?string $declaredMimeType = null,
    ) {}

    public static function make(): self
    {
        return new self;
    }

    public function parent(Node|int|string|null $parent): self
    {
        return new self(parent: $parent, name: $this->name, onConflict: $this->onConflict, declaredMimeType: $this->declaredMimeType);
    }

    public function name(?string $name): self
    {
        return new self(parent: $this->parent, name: $name, onConflict: $this->onConflict, declaredMimeType: $this->declaredMimeType);
    }

    public function onConflict(OnNameConflict $onConflict): self
    {
        return new self(parent: $this->parent, name: $this->name, onConflict: $onConflict, declaredMimeType: $this->declaredMimeType);
    }

    public function declaredMimeType(?string $declaredMimeType): self
    {
        return new self(parent: $this->parent, name: $this->name, onConflict: $this->onConflict, declaredMimeType: $declaredMimeType);
    }
}
