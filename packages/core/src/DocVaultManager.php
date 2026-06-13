<?php

declare(strict_types=1);

namespace DocVault;

use DocVault\Contracts\PermissionResolver;

/**
 * Central entry point for host-app integration: register the permission resolver
 * and extend the document-type registry.
 *
 * Business logic (actual document/version/folder services) is added in later
 * milestones — see docvault-spec.md.
 */
final class DocVaultManager
{
    private ?PermissionResolver $permissionResolver = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $documentTypes = [];

    /**
     * Tell DocVault how to delegate authorization to the host application.
     */
    public function resolvePermissionsWith(PermissionResolver $resolver): void
    {
        $this->permissionResolver = $resolver;
    }

    public function permissionResolver(): ?PermissionResolver
    {
        return $this->permissionResolver;
    }

    /**
     * Register a custom document type.
     *
     * @param array<string, mixed> $definition
     */
    public function registerDocumentType(string $type, array $definition): void
    {
        $this->documentTypes[$type] = $definition;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function documentTypes(): array
    {
        return $this->documentTypes;
    }
}
