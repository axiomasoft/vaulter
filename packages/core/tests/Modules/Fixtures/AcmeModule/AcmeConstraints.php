<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures\AcmeModule;

/** What an actor may still do; a restriction may only take abilities away. */
final readonly class AcmeConstraints
{
    /** @param list<string> $abilities */
    public function __construct(public array $abilities) {}

    public function isSubsetOf(self $other): bool
    {
        return array_diff($this->abilities, $other->abilities) === [];
    }

    public function without(string ...$abilities): self
    {
        return new self(array_values(array_diff($this->abilities, $abilities)));
    }

    public function with(string ...$abilities): self
    {
        return new self(array_values(array_unique([...$this->abilities, ...$abilities])));
    }
}
