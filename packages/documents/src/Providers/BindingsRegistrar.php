<?php

declare(strict_types=1);

namespace Vaulter\Documents\Providers;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Doctor\Checks\ConfigValidCheck;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Contracts\DocumentExporter;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\DocumentsModule;
use Vaulter\Documents\Export\DocumentExporterRegistry;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Operations\CommentOperations;
use Vaulter\Documents\Operations\DocumentOperations;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Models\Node;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Kernel;
use Vaulter\Modules\ModuleBindings;

/**
 * The container wiring of the documents module ({@see DocumentsModule}): config, types,
 * exporters, operations, presentation, the sanitizer, and config validator, and
 * at boot the document relation of the node model and the frozen type and exporter registries.
 */
#[Internal]
final class BindingsRegistrar implements ModuleBindings
{
    public function register(Application $app): void
    {
        $app->singleton(DocumentsConfig::class, static fn (Container $app): DocumentsConfig => new DocumentsConfig($app->make('config')));
        $app->bind('vaulter.config_validator.documents', static fn (Container $app): Closure => static function () use ($app): void {
            $config = $app->make(DocumentsConfig::class);
            $config->model('document_revision');
            $config->model('node_comment');
            $config->assertDistinctHubSecrets();
            $config->hubTokenTtl();
        });
        $app->tag('vaulter.config_validator.documents', ConfigValidCheck::TAG);

        $app->singleton(DocumentTypeRegistry::class);
        $app->singleton(DocumentExporterRegistry::class);
        $app->singleton(DocumentsManager::class);
        // Operations carry the handle's context, so every resolution is a new instance.
        $app->bind(DocumentOperations::class);
        $app->bind(CommentOperations::class);
        $app->alias(DocumentsManager::class, 'vaulter.documents');

        // The export filename seam is a capability of DocumentsModule (the node's basename plus the exporter's
        // extension): ExportDocumentAction is final, so a host wanting a different scheme rebinds the contract
        // instead of forking the action.

        // Second echelon of the stored-XSS fix: TipTapRenderer takes
        // the sanitizer through its constructor (never resolved inline from the
        // container) so every HTML render — including content persisted before the
        // store-time validator existed — is sanitized on the way out. Singleton: the
        // sanitizer is stateless and config-immutable per request.
        $app->singleton(HtmlSanitizerInterface::class, static function (): HtmlSanitizer {
            $config = (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowLinkSchemes(['https', 'http', 'mailto'])
                ->allowMediaSchemes(['https', 'http']);

            return new HtmlSanitizer($config);
        });
    }

    public function boot(Application $app): void
    {
        $config = $app->make(DocumentsConfig::class);
        $config->model('document_revision');
        $config->model('node_comment');
        Document::configure($app->make(DatabaseConfig::class), $app->make(DriveConfig::class), $config);
        // Core reads a document head through this relation without depending on
        // the documents package; subclasses of the configured node model inherit it.
        Node::resolveRelationUsing('document', static fn (Node $node): HasOne => $node->hasOne(Document::class, 'node_id'));
        $config->assertDistinctHubSecrets();
        $config->assertCollaborationSecrets($app);

        self::bootDocumentTypes($app, $config);
        self::bootDocumentExporters($app, $config);
    }

    /**
     * The modules' contributions, config and tagged types join the ones providers registered; the set freezes once
     * every provider has booted, and only then can the default type be checked.
     */
    private static function bootDocumentTypes(Application $app, DocumentsConfig $config): void
    {
        $types = $app->make(DocumentTypeRegistry::class);
        $contributed = [];
        foreach ($app->make(Kernel::class)->extensions()->resolve(DocumentType::class) as $type) {
            $types->register($type);
            $contributed[$type::class] = true;
        }
        foreach ($config->types() as $class) {
            // The shipped config list names the built-ins the modules already contributed: only the host's own are new.
            if (!isset($contributed[$class])) {
                $types->register($class);
            }
        }
        foreach ($app->tagged('vaulter.document_types') as $type) {
            // Refused like a class name that does not resolve to a type, never skipped.
            if (!$type instanceof DocumentType && !is_string($type)) {
                throw new InvalidConfigurationException(sprintf('[Vaulter] Document type "%s" did not resolve to a %s.', get_debug_type($type), DocumentType::class));
            }
            $types->register($type);
        }

        $app->booted(static function () use ($types, $config): void {
            $types->freeze();
            if (!$types->has($config->defaultType())) {
                throw new InvalidConfigurationException(sprintf('[Vaulter] vaulter-documents.default_type "%s" is not a registered document type.', $config->defaultType()));
            }
        });
    }

    /** The modules' contributions, config and tagged exporters join the ones providers registered; the set freezes after boot. */
    private static function bootDocumentExporters(Application $app, DocumentsConfig $config): void
    {
        $exporters = $app->make(DocumentExporterRegistry::class);
        $contributed = [];
        foreach ($app->make(Kernel::class)->extensions()->resolve(DocumentExporter::class) as $exporter) {
            $exporters->register($exporter);
            $contributed[$exporter::class] = true;
        }
        foreach ($config->exporters() as $class) {
            if (!isset($contributed[$class])) {
                $exporters->register($class);
            }
        }
        foreach ($app->tagged('vaulter.exporters') as $exporter) {
            if (!$exporter instanceof DocumentExporter && !is_string($exporter)) {
                throw new InvalidConfigurationException(sprintf('[Vaulter] Document exporter "%s" did not resolve to a %s.', get_debug_type($exporter), DocumentExporter::class));
            }
            $exporters->register($exporter);
        }

        $app->booted(static function () use ($exporters): void {
            $exporters->freeze();
        });
    }
}
