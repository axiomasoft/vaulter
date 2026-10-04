<?php

declare(strict_types=1);

namespace Vaulter;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Contracts\Drive\DriveHandleFactory;
use Vaulter\Contracts\Handlers\DocumentExporter;
use Vaulter\Contracts\Handlers\FileHandlerRegistry;
use Vaulter\Exceptions\Documents\UnknownExportFormatException;
use Vaulter\Exceptions\Support\MissingPackageException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Support\Config;

/**
 * Public entry point for host-app integration: open Drive handles, register drive
 * profiles, extend the document-type/handler/exporter registries.
 */
final class VaulterManager
{
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
     * Open a Drive handle bound to a host entity's storage within a panel (§2.1).
     * Get-or-creates the v_storages row (idempotent, root folder auto-provisioned)
     * and returns an ergonomic {@see DriveHandle} whose methods delegate to the Drive
     * Actions/repositories. This is the package's primary integration entry point.
     *
     * The concrete handle ships in vaulter-drive (P2 #5): core resolves the
     * DriveHandleFactory seam that package binds. Without vaulter-drive installed the
     * binding is absent and this fails loudly — file behaviour needs the drive package.
     */
    public function for(Model $entity, ?string $panel = null): DriveHandle
    {
        return $this->driveFactory()->forOwner($entity, $panel ?? 'default');
    }

    /**
     * Open a Drive handle bound to an already-resolved storage row. This is the
     * seam the HTTP layer uses: a route-model-bound Drive (resolved through the
     * swappable config('vaulter.models.storage')) maps straight to a DriveHandle
     * without re-deriving the owner. The handle's panel mirrors the storage's own
     * panel so its Actions run inside the right panel scope.
     */
    public function forStorage(Drive $storage): DriveHandle
    {
        return $this->driveFactory()->forStorage($storage);
    }

    /**
     * Resolve the vaulter-drive handle factory (P2 #5 seam). Bound by the drive
     * package's provider; absent it, {@see resolveSeam()} raises a domain exception
     * naming the missing composer package (F-P3.5-01).
     */
    private function driveFactory(): DriveHandleFactory
    {
        /** @var DriveHandleFactory $factory */
        $factory = $this->resolveSeam(DriveHandleFactory::class, 'axioma-studio/vaulter-drive');

        return $factory;
    }

    /**
     * Resolve a cross-package seam contract, translating the container's generic
     * {@see BindingResolutionException} ("Target [...] is not instantiable.") into
     * a Vaulter-branded exception naming the composer package to install
     * (F-P3.3-02 / F-P3.5-01) — the docblocks of {@see FileHandlerRegistry} and
     * {@see NodePresenter} already claimed a
     * "fails loudly with an install hint", which this method now actually is.
     *
     * @param class-string $contract
     */
    private function resolveSeam(string $contract, string $package): object
    {
        try {
            /** @var object $instance */
            $instance = $this->container->make($contract);

            return $instance;
        } catch (BindingResolutionException) {
            throw MissingPackageException::forContract($contract, $package);
        }
    }

    /**
     * The container-singleton profile registry (one Profile per id).
     */
    public function profiles(): ProfileRegistry
    {
        return $this->container->make(ProfileRegistry::class);
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
     * The container-bound handler registry (§4.5). The single source of truth for
     * format resolution — kept here as a thin accessor so callers reach it through
     * the facade without inflating the manager. The engine is core-owned (P0.4/D10,
     * superseding H.5/T5): it always resolves, even without vaulter-documents
     * installed (a drive-only host still gets preview/download resolution; TipTap and
     * other rich formats are additional handlers a package/host tags in).
     */
    public function handlers(): FileHandlerRegistry
    {
        return $this->container->make(FileHandlerRegistry::class);
    }

    /**
     * Register a custom export format at runtime — symmetric to
     * {@see handlers()} (F-P3.3-01): an FQCN is resolved through the
     * container, exactly like the handler seam does.
     *
     * @param DocumentExporter|class-string<DocumentExporter> $exporter
     */
    public function registerExporter(DocumentExporter|string $exporter): void
    {
        /** @var DocumentExporter $instance */
        $instance = $exporter instanceof DocumentExporter
            ? $exporter
            : $this->container->make($exporter);

        $this->exporters[$instance->format()] = $instance;
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
}
