<?php

declare(strict_types=1);

namespace Vaulter\Documents\Registries;

final class DocumentTypeRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $types = [];

    /** @param array<string, mixed> $definition */
    public function register(string $type, array $definition): void
    {
        $this->types[$type] = $definition;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->types;
    }
}
