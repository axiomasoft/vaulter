<?php

declare(strict_types=1);

namespace Vaulter;

use Illuminate\Contracts\Container\Container;
use Vaulter\Contracts\CollaborationDriver;
use Vaulter\Contracts\DocumentExporter;
use Vaulter\Contracts\PermissionResolver;
use Vaulter\Exceptions\UnknownExportFormatException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Support\Config;

/**
 * Public entry point for host-app integration: register the permission resolver,
 * extend the document-type registry, and resolve the collaboration driver.
 */
final class VaulterManager
{
    private ?PermissionResolver $permissionResolver = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $documentTypes = [];

    /**
     * @var array<string, DocumentExporter>
     */
    private array $exporters = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Tell Vaulter how to delegate authorization to the host application.
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
     * Register a custom export format.
     */
    public function registerExporter(DocumentExporter $exporter): void
    {
        $this->exporters[$exporter->format()] = $exporter;
    }

    public function exporter(string $format): DocumentExporter
    {
        if (isset($this->exporters[$format])) {
            return $this->exporters[$format];
        }

        $configured = Config::exporters();

        if (isset($configured[$format])) {
            /** @var DocumentExporter $exporter */
            $exporter = $this->container->make($configured[$format]);

            return $this->exporters[$format] = $exporter;
        }

        throw UnknownExportFormatException::forFormat($format, $this->exporterFormats());
    }

    /**
     * @return list<string>
     */
    public function exporterFormats(): array
    {
        return array_values(array_unique([
            ...array_keys(Config::exporters()),
            ...array_keys($this->exporters),
        ]));
    }

    /**
     * Resolve a collaboration driver (defaults to config('vaulter.collaboration.driver')).
     */
    public function driver(?string $name = null): CollaborationDriver
    {
        $name ??= Config::collaborationDriverName();
        $config = Config::driverConfig($name);
        $class = $config['class'] ?? null;

        if (!is_string($class) || !is_a($class, CollaborationDriver::class, allow_string: true)) {
            throw new VaulterException(
                sprintf('[Vaulter] Unknown collaboration driver [%s]. ', $name)
                .'Fix: add it under config(vaulter.collaboration.drivers) with a "class" implementing CollaborationDriver. '
                .'Docs: https://github.com/axioma-studio/vaulter/blob/main/docs/guide/errors.md#unknown-collaboration-driver',
            );
        }

        /** @var CollaborationDriver $driver */
        $driver = $this->container->make($class);

        return $driver;
    }
}
