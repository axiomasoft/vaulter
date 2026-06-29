<?php

declare(strict_types=1);

namespace Vaulter;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Contracts\CollaborationDriver;
use Vaulter\Contracts\PermissionResolver;
use Vaulter\Contracts\ShareTokenGenerator;
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
        $this->app->singleton(VaulterManager::class);
        $this->app->alias(VaulterManager::class, 'vaulter');

        $this->app->bind(ShareTokenGenerator::class, Config::shareTokenGeneratorClass());

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

                return app(VaulterManager::class)->driver()->authorizeChannel($user, $document);
            },
        );
    }
}
