<?php

declare(strict_types=1);

namespace DocVault;

use DocVault\Contracts\CollaborationDriver;
use DocVault\Contracts\PermissionResolver;
use DocVault\Support\Config;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class DocVaultServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('docvault')
            ->hasConfigFile()
            ->discoversMigrations()
            ->runsMigrations()
            ->hasRoute('api')
            ->hasInstallCommand(static function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('axioma-studio/doc-vault');
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(DocVaultManager::class);
        $this->app->alias(DocVaultManager::class, 'docvault');

        $resolverClass = Config::permissionResolverClass();

        if ($resolverClass !== null) {
            $this->app->bind(PermissionResolver::class, $resolverClass);
        }

        $this->app->bind(
            CollaborationDriver::class,
            fn (): CollaborationDriver => $this->app->make(DocVaultManager::class)->driver(),
        );
    }

    public function packageBooted(): void
    {
        $this->registerBroadcastChannel();
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

                return app(DocVaultManager::class)->driver()->authorizeChannel($user, $document);
            },
        );
    }
}
