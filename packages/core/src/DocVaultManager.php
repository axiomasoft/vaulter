<?php

declare(strict_types=1);

namespace DocVault;

use DocVault\Contracts\CollaborationDriver;
use DocVault\Contracts\PermissionResolver;
use DocVault\Exceptions\DocVaultException;
use DocVault\Support\Config;
use Illuminate\Contracts\Container\Container;

/**
 * Public entry point for host-app integration: register the permission resolver,
 * extend the document-type registry, and resolve the collaboration driver.
 */
final class DocVaultManager
{
    private ?PermissionResolver $permissionResolver = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $documentTypes = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Tell DocVault how to delegate authorization to the host application.
     */
    public function resolvePermissionsWith(PermissionResolver $resolver): void
    {
        $this->permissionResolver = $resolver;
    }

    public function permissionResolver(): ?PermissionResolver
    {
        if ($this->permissionResolver instanceof PermissionResolver) {
            return $this->permissionResolver;
        }

        $class = Config::permissionResolverClass();

        if ($class === null) {
            return null;
        }

        $resolver = $this->container->make($class);

        return $this->permissionResolver = $resolver instanceof PermissionResolver ? $resolver : null;
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

    /**
     * Resolve a collaboration driver (defaults to config('docvault.collaboration.driver')).
     */
    public function driver(?string $name = null): CollaborationDriver
    {
        $name ??= Config::collaborationDriverName();
        $config = Config::driverConfig($name);
        $class = $config['class'] ?? null;

        if (!is_string($class) || !is_a($class, CollaborationDriver::class, allow_string: true)) {
            throw new DocVaultException(
                sprintf('[DocVault] Unknown collaboration driver [%s]. ', $name)
                .'Fix: add it under config(docvault.collaboration.drivers) with a "class" implementing CollaborationDriver. '
                .'Docs: https://github.com/axioma-studio/doc-vault/blob/main/docs/guide/errors.md#unknown-collaboration-driver',
            );
        }

        /** @var CollaborationDriver $driver */
        $driver = $this->container->make($class);

        return $driver;
    }
}
