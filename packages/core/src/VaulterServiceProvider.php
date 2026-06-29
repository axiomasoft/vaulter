<?php

declare(strict_types=1);

namespace Vaulter;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Console\GcBlobsCommand;
use Vaulter\Console\MakePanelCommand;
use Vaulter\Console\PanelMigrationsCommand;
use Vaulter\Console\ReconcileBlobsCommand;
use Vaulter\Contracts\BlobStore;
use Vaulter\Contracts\CollaborationDriver;
use Vaulter\Contracts\ContentStore;
use Vaulter\Contracts\PermissionResolver;
use Vaulter\Contracts\ShareTokenGenerator;
use Vaulter\Contracts\StorageDriver;
use Vaulter\Drive\StorageManager;
use Vaulter\Panels\PanelRegistry;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Support\Config;

final class VaulterServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('vaulter')
            ->hasConfigFile()
            ->discoversMigrations()
            ->runsMigrations()
            ->hasRoute('api')
            ->hasCommand(MakePanelCommand::class)
            ->hasCommand(PanelMigrationsCommand::class)
            ->hasCommand(GcBlobsCommand::class)
            ->hasCommand(ReconcileBlobsCommand::class)
            ->hasInstallCommand(static function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('axioma-studio/vaulter');
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(IdStrategyResolver::class);

        $this->app->singleton(
            PanelRegistry::class,
            static fn (): PanelRegistry => PanelRegistry::fromConfig(
                providerClasses: Config::panels(),
                defaultClass: Config::defaultPanel(),
            ),
        );

        $this->app->singleton(VaulterManager::class);
        $this->app->alias(VaulterManager::class, 'vaulter');

        // Drive domain (M1c): the storage get-or-create + root provisioning
        // service. Its deps (read/store repositories) are auto-resolved; binding it
        // as a singleton makes the seam explicit for swaps/tests.
        $this->app->singleton(StorageManager::class);

        $this->app->bind(ShareTokenGenerator::class, Config::shareTokenGeneratorClass());

        // CAS services (H.3) — swappable via config('vaulter.cas.*'). StorageDriver
        // and ContentStore are stateless wrappers (singletons); BlobStore is the
        // race-free lifecycle orchestrator.
        $this->app->singleton(StorageDriver::class, Config::storageDriverClass());
        $this->app->singleton(ContentStore::class, Config::contentStoreClass());
        $this->app->bind(BlobStore::class, Config::blobStoreClass());

        $resolverClass = Config::permissionResolverClass();

        if ($resolverClass !== null) {
            $this->app->bind(PermissionResolver::class, $resolverClass);
        }

        $this->app->bind(
            CollaborationDriver::class,
            fn (): CollaborationDriver => $this->app->make(VaulterManager::class)->driver(),
        );
    }

    public function packageBooted(): void
    {
        $this->registerRouteBindings();
        $this->registerBroadcastChannel();
    }

    /**
     * Bind the Drive REST route parameters {storage} and {node} to the SWAPPABLE
     * models (config('vaulter.models.*')) so the binding keeps resolving when a
     * host points the config at a subclass. A miss 404s (findOrFail). Node uses
     * the Drive trash (a trashed_at column), not the SoftDeletes deleted_at, so a
     * trashed node still resolves by default — restore/force/download need it.
     */
    private function registerRouteBindings(): void
    {
        Route::bind('storage', static function (string $value): Model {
            $model = Config::storageModel();

            return $model::query()->findOrFail($value);
        });

        Route::bind('node', static function (string $value): Model {
            $model = Config::nodeModel();

            return $model::query()->findOrFail($value);
        });
    }

    private function registerBroadcastChannel(): void
    {
        Broadcast::channel(
            Config::channelPrefix().'.{documentId}',
            static function (Authenticatable $user, int|string $documentId): bool {
                $model = Config::documentModel();
                $document = $model::query()->find($documentId);

                if ($document === null) {
                    return false;
                }

                return app(VaulterManager::class)->driver()->authorizeChannel($user, $document);
            },
        );
    }
}
