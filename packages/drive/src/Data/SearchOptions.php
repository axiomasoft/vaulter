<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;

#[Api]
final readonly class SearchOptions
{
    public function __construct(
        public int $perPage = 50,
        public ?string $cursor = null,
        public NodeSnapshot|string|int|null $within = null,
    ) {
        if ($perPage < 1 || $perPage > 200) {
            throw new InvalidArgumentException('Page size must be between 1 and 200.');
        }
    }

    public static function make(): self
    {
        return new self;
    }

    public function perPage(int $perPage): self
    {
        return new self(perPage: $perPage, cursor: $this->cursor, within: $this->within);
    }

    public function cursor(?string $cursor): self
    {
        return new self(perPage: $this->perPage, cursor: $cursor, within: $this->within);
    }

    public function within(NodeSnapshot|string|int|null $within): self
    {
        return new self(perPage: $this->perPage, cursor: $this->cursor, within: $within);
    }
}
