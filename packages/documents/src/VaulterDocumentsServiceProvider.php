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
use Vaulter\Documents\Console\MakeDocumentTypeCommand;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Providers\BindingsRegistrar;
use Vaulter\Documents\Providers\CollaborationRegistrar;
use Vaulter\Documents\Providers\HttpRegistrar;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
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
        $package->name('vaulter-documents')->hasConfigFile()->hasCommand(MakeDocumentTypeCommand::class);

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
        $this->bootDocumentTypes($config);
    }

    /**
     * Config and tagged types join the ones providers registered; the set freezes once
     * every provider has booted, and only then can the default type be checked.
     */
    private function bootDocumentTypes(DocumentsConfig $config): void
    {
        $types = $this->app->make(DocumentTypeRegistry::class);
        foreach ($config->types() as $class) {
            $types->register($class);
        }
        foreach ($this->app->tagged('vaulter.document_types') as $type) {
            // Refused like a class name that does not resolve to a type, never skipped.
            if (!$type instanceof DocumentType && !is_string($type)) {
                throw new InvalidConfigurationException(sprintf('[Vaulter] Document type "%s" did not resolve to a %s.', get_debug_type($type), DocumentType::class));
            }
            $types->register($type);
        }

        $this->app->booted(static function () use ($types, $config): void {
            $types->freeze();
            if (!$types->has($config->defaultType())) {
                throw new InvalidConfigurationException(sprintf('[Vaulter] vaulter-documents.default_type "%s" is not a registered document type.', $config->defaultType()));
            }
        });
    }
}
