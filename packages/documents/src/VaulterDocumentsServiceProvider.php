<?php

declare(strict_types=1);

namespace Vaulter\Documents;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Documents\Configuration\DocumentsConfig;
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
        $package->name('vaulter-documents')->hasConfigFile();

        (new HttpRegistrar)->configure($package);
    }

    public function packageRegistered(): void
    {
        (new BindingsRegistrar($this->app))->register();
        (new CollaborationRegistrar($this->app))->register();
    }

    public function packageBooted(): void
    {
        $config = $this->app->make(DocumentsConfig::class);
        $config->model('document_revision');
        $config->model('node_comment');
        ConfigValidator::assertCollaborationSecrets(
            enabled: $config->collaborationEnabled(),
            jwtSecret: $config->hubJwtSecret(),
            webhookSecret: $config->hubWebhookSecret(),
            app: $this->app,
        );

        (new CollaborationRegistrar($this->app))->boot();
    }
}
