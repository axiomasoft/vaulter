<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data;

use InvalidArgumentException;
use Vaulter\Attributes\Api;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Enums\NodeSort;

#[Api]
final readonly class ListOptions
{
    /** @param list<NodeKind> $kinds */
    public function __construct(
        public int $perPage = 50,
        public ?string $cursor = null,
        public NodeSort $sort = NodeSort::Default,
        public bool $descending = false,
        public array $kinds = [],
    ) {
        if ($perPage < 1 || $perPage > 200) {
            throw new InvalidArgumentException('Page size must be between 1 and 200.');
        }
        self::validateKinds($kinds);
    }

    /** @param array<array-key, mixed> $kinds */
    private static function validateKinds(array $kinds): void
    {
        if (!array_is_list($kinds)) {
            throw new InvalidArgumentException('Kinds must be a list of NodeKind values.');
        }
        foreach ($kinds as $kind) {
            if (!$kind instanceof NodeKind) {
                throw new InvalidArgumentException('Kinds must be a list of NodeKind values.');
            }
        }
    }

    public static function make(): self
    {
        return new self;
    }

    public function perPage(int $perPage): self
    {
        return new self(perPage: $perPage, cursor: $this->cursor, sort: $this->sort, descending: $this->descending, kinds: $this->kinds);
    }

    public function cursor(?string $cursor): self
    {
        return new self(perPage: $this->perPage, cursor: $cursor, sort: $this->sort, descending: $this->descending, kinds: $this->kinds);
    }

    public function sort(NodeSort $sort, bool $descending = false): self
    {
        return new self(perPage: $this->perPage, cursor: $this->cursor, sort: $sort, descending: $descending, kinds: $this->kinds);
    }

    public function descending(bool $descending): self
    {
        return new self(perPage: $this->perPage, cursor: $this->cursor, sort: $this->sort, descending: $descending, kinds: $this->kinds);
    }

    /** @param list<NodeKind> $kinds */
    public function kinds(array $kinds): self
    {
        return new self(perPage: $this->perPage, cursor: $this->cursor, sort: $this->sort, descending: $this->descending, kinds: $kinds);
    }
}
