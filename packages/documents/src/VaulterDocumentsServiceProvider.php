<?php

declare(strict_types=1);

namespace Vaulter\Documents;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Providers\BindingsRegistrar;
use Vaulter\Documents\Providers\CollaborationRegistrar;
use Vaulter\Documents\Providers\HttpRegistrar;
use Vaulter\Models\Drive\Node;

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
        Document::configure($this->app->make(DatabaseConfig::class), $this->app->make(VaulterConfig::class), $config);
        // Core reads a document head through this relation without depending on
        // the documents package; subclasses of the configured node model inherit it.
        Node::resolveRelationUsing('document', static fn (Node $node): HasOne => $node->hasOne(Document::class, 'node_id'));
        ConfigValidator::assertCollaborationSecrets(
            enabled: $config->collaborationEnabled(),
            jwtSecret: $config->hubJwtSecret(),
            webhookSecret: $config->hubWebhookSecret(),
            app: $this->app,
        );

        (new CollaborationRegistrar($this->app))->boot();
    }
}
