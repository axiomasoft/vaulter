<?php

declare(strict_types=1);

namespace Vaulter;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Contracts\Documents\DocumentExporter;
use Vaulter\Contracts\Documents\DocumentHandler;
use Vaulter\Contracts\Documents\HandlerRegistry;
use Vaulter\Contracts\Documents\NodeHandlerResolver;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Contracts\Drive\DriveHandleFactory;
use Vaulter\Exceptions\Documents\UnknownExportFormatException;
use Vaulter\Exceptions\Support\MissingPackageException;
use Vaulter\Models\Drive\Storage;
use Vaulter\Panels\Panel;
use Vaulter\Panels\PanelRegistry;
use Vaulter\Panels\StoragePanelProvider;
use Vaulter\Support\Config;

/**
 * Public entry point for host-app integration: open Drive handles, register storage
 * panels, extend the document-type/handler/exporter registries.
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
     * seam the HTTP layer uses: a route-model-bound Storage (resolved through the
     * swappable config('vaulter.models.storage')) maps straight to a DriveHandle
     * without re-deriving the owner. The handle's panel mirrors the storage's own
     * panel so its Actions run inside the right panel scope.
     */
    public function forStorage(Storage $storage): DriveHandle
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
     * (F-P3.3-02 / F-P3.5-01) — the docblocks of {@see HandlerRegistry} and
     * {@see NodeHandlerResolver} already claimed a
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
     * Resolve a storage panel by id (default 'default'). Any future per-panel
     * resolved state (drivers, exporters) must be keyed BY this panel id, never
     * a single shared memoization (H.1) — so the registry is the single source
     * keyed by panel id.
     */
    public function panel(string $name = 'default'): Panel
    {
        return $this->panels()->get($name);
    }

    /**
     * The container-singleton panel registry (one Panel per id).
     */
    public function panels(): PanelRegistry
    {
        return $this->container->make(PanelRegistry::class);
    }

    /**
     * Register a storage panel at runtime, by provider or by an already-built
     * Panel. The registry validates it (H.1) and keys it by its id.
     */
    public function registerPanel(StoragePanelProvider|Panel $panel): void
    {
        $registry = $this->panels();

        if ($panel instanceof StoragePanelProvider) {
            $registry->registerProvider($panel);

            return;
        }

        $registry->register($panel);
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
     * Register a custom document handler at runtime — the format-extensibility seam
     * (§4.5 / H.5). Thin delegation to the container-bound {@see HandlerRegistry}
     * (the single source of truth, concretely vaulter-documents's DocumentHandlerRegistry
     * — P2 #5); accepts an FQCN or an instance. A NEW format is a one-class + this
     * call — no migration, no core change. Fails loudly without vaulter-documents
     * installed — document handling needs the documents package.
     *
     * @param DocumentHandler|class-string<DocumentHandler> $handler
     */
    public function registerHandler(DocumentHandler|string $handler): void
    {
        $this->handlers()->register($handler);
    }

    /**
     * The container-bound document handler registry (§4.5). The single source of
     * truth for format resolution — kept here as a thin accessor so callers reach it
     * through the facade without inflating the manager. The concrete engine ships in
     * vaulter-documents (P2 #5); this seam fails loudly (with an install hint,
     * F-P3.3-02) without it installed.
     */
    public function handlers(): HandlerRegistry
    {
        /** @var HandlerRegistry $registry */
        $registry = $this->resolveSeam(HandlerRegistry::class, 'axioma-studio/vaulter-documents');

        return $registry;
    }

    /**
     * Register a custom export format at runtime — symmetric to
     * {@see registerHandler()} (F-P3.3-01): an FQCN is resolved through the
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
