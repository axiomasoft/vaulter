<?php

declare(strict_types=1);

namespace Vaulter;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Actions\Drive\CopyNodeAction;
use Vaulter\Actions\Drive\CreateFolderAction;
use Vaulter\Actions\Drive\ForceDeleteNodeAction;
use Vaulter\Actions\Drive\MoveNodeAction;
use Vaulter\Actions\Drive\RenameNodeAction;
use Vaulter\Actions\Drive\RestoreNodeAction;
use Vaulter\Actions\Drive\TrashNodeAction;
use Vaulter\Actions\Drive\UploadFileAction;
use Vaulter\Contracts\CollaborationDriver;
use Vaulter\Contracts\DocumentExporter;
use Vaulter\Contracts\DocumentHandler;
use Vaulter\Contracts\PermissionResolver;
use Vaulter\Documents\DocumentHandlerRegistry;
use Vaulter\Drive\DriveContext;
use Vaulter\Drive\StorageManager;
use Vaulter\Exceptions\UnknownExportFormatException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Models\Storage;
use Vaulter\Panels\Panel;
use Vaulter\Panels\PanelRegistry;
use Vaulter\Panels\StoragePanelProvider;
use Vaulter\Repositories\NodeReadRepository;
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
     * Open a Drive handle bound to a host entity's storage within a panel (§2.1).
     * Get-or-creates the v_storages row (idempotent, root folder auto-provisioned)
     * and returns an ergonomic DriveContext whose methods delegate to the Drive
     * Actions/repositories. This is the package's primary integration entry point.
     */
    public function for(Model $entity, ?string $panel = null): DriveContext
    {
        $panel ??= 'default';

        $storage = $this->container->make(StorageManager::class)->forOwner(owner: $entity, panel: $panel);

        return $this->forStorage(storage: $storage);
    }

    /**
     * Open a Drive handle bound to an already-resolved storage row. This is the
     * seam the HTTP layer uses: a route-model-bound Storage (resolved through the
     * swappable config('vaulter.models.storage')) maps straight to a DriveContext
     * without re-deriving the owner. The handle's panel mirrors the storage's own
     * panel so its Actions run inside the right panel scope.
     */
    public function forStorage(Storage $storage): DriveContext
    {
        return new DriveContext(
            storage: $storage,
            panel: $storage->panel,
            storages: $this->container->make(StorageManager::class),
            nodes: $this->container->make(NodeReadRepository::class),
            createFolder: $this->container->make(CreateFolderAction::class),
            uploadFile: $this->container->make(UploadFileAction::class),
            moveNode: $this->container->make(MoveNodeAction::class),
            renameNode: $this->container->make(RenameNodeAction::class),
            copyNode: $this->container->make(CopyNodeAction::class),
            trashNode: $this->container->make(TrashNodeAction::class),
            restoreNode: $this->container->make(RestoreNodeAction::class),
            forceDeleteNode: $this->container->make(ForceDeleteNodeAction::class),
        );
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
     * Register a custom document handler at runtime — the format-extensibility seam
     * (§4.5 / H.5). Thin delegation to the container-bound DocumentHandlerRegistry
     * (the single source of truth); accepts an FQCN or an instance. A NEW format is
     * a one-class + this call — no migration, no core change.
     *
     * @param DocumentHandler|class-string<DocumentHandler> $handler
     */
    public function registerHandler(DocumentHandler|string $handler): void
    {
        $this->container->make(DocumentHandlerRegistry::class)->register($handler);
    }

    /**
     * The container-bound document handler registry (§4.5). The single source of
     * truth for format resolution — kept here as a thin accessor so callers reach it
     * through the facade without inflating the manager.
     */
    public function handlers(): DocumentHandlerRegistry
    {
        return $this->container->make(DocumentHandlerRegistry::class);
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
