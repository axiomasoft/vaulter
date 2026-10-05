<?php

declare(strict_types=1);

namespace Vaulter\Documents;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Documents\Providers\BindingsRegistrar;
use Vaulter\Documents\Providers\CollaborationRegistrar;
use Vaulter\Documents\Providers\HttpRegistrar;

/**
 * Registers the document use cases, revisions, comments, collaboration and HTTP.
 * Core owns the schema and shared handler registry; registrars contribute the
 * module's bindings and preserve the package registration and boot phases.
 */
final class VaulterDocumentsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('vaulter-documents');

        (new HttpRegistrar)->configure($package);
    }

    public function packageRegistered(): void
    {
        (new BindingsRegistrar($this->app))->register();
        (new CollaborationRegistrar($this->app))->register();
    }

    public function packageBooted(): void
    {
        (new CollaborationRegistrar($this->app))->boot();
    }
}
