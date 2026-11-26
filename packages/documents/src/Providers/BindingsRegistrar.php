<?php

declare(strict_types=1);

namespace Vaulter\Documents\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Packman\Attributes\Internal;
use Packman\Config\Laravel\ConfigSectionRegistration;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Vaulter\Configuration\CompiledOptionsCache;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Doctor\Checks\ConfigValidCheck;
use Vaulter\Documents\Actions\Documents\RunAtomicDocumentMutation;
use Vaulter\Documents\Configuration\DocumentsBroadcastOptions;
use Vaulter\Documents\Configuration\DocumentsCollaborationOptions;
use Vaulter\Documents\Configuration\DocumentsCommentsOptions;
use Vaulter\Documents\Configuration\DocumentsContentOptions;
use Vaulter\Documents\Configuration\DocumentsModelsOptions;
use Vaulter\Documents\Configuration\DocumentsOptions;
use Vaulter\Documents\Configuration\DocumentsRevisionsOptions;
use Vaulter\Documents\Configuration\TipTapOptions;
use Vaulter\Documents\Contracts\AtomicDocumentMutation;
use Vaulter\Documents\Contracts\DocumentExporter;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\DocumentsModule;
use Vaulter\Documents\Export\DocumentExporterRegistry;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Documents\DocumentPayload;
use Vaulter\Documents\Models\Documents\DocumentSyncState;
use Vaulter\Documents\Models\Revisions\DocumentRevisionPayload;
use Vaulter\Documents\Models\Revisions\DocumentRevisionSyncState;
use Vaulter\Documents\Operations\CommentOperations;
use Vaulter\Documents\Operations\DocumentOperations;
use Vaulter\Documents\State\CollaborativeState;
use Vaulter\Documents\State\DatabaseCollaborativeState;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Models\Node;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Kernel;
use Vaulter\Modules\ModuleBindings;

/**
 * Container wiring of {@see DocumentsModule}: compiled options, types,
 * exporters, operations, presentation, the sanitizer, and boot validation.
 */
#[Internal]
final class BindingsRegistrar implements ModuleBindings
{
    public function register(Application $app): void
    {
        $app->bind(DocumentsOptions::class, static fn (Container $app): DocumentsOptions => CompiledOptionsCache::resolve(
            $app,
            'vaulter-documents',
            static fn (array $raw): DocumentsOptions => DocumentsOptions::fromArray($raw, 'vaulter-documents'),
        ));
        $app->bind(DocumentsCollaborationOptions::class, static fn (Container $app): DocumentsCollaborationOptions => $app->make(DocumentsOptions::class)->collaboration);
        $app->bind(DocumentsContentOptions::class, static fn (Container $app): DocumentsContentOptions => $app->make(DocumentsOptions::class)->content);
        $app->bind(DocumentsRevisionsOptions::class, static fn (Container $app): DocumentsRevisionsOptions => $app->make(DocumentsOptions::class)->revisions);
        $app->bind(DocumentsCommentsOptions::class, static fn (Container $app): DocumentsCommentsOptions => $app->make(DocumentsOptions::class)->comments);
        $app->bind(TipTapOptions::class, static fn (Container $app): TipTapOptions => $app->make(DocumentsOptions::class)->tiptap);
        $app->bind(DocumentsBroadcastOptions::class, static fn (Container $app): DocumentsBroadcastOptions => $app->make(DocumentsOptions::class)->broadcast);
        $app->bind(DocumentsModelsOptions::class, static fn (Container $app): DocumentsModelsOptions => $app->make(DocumentsOptions::class)->models);

        $validate = static function () use ($app): void {
            self::buildOptions($app);
        };
        $app->instance('vaulter.config_validator.documents', $validate);
        $app->tag('vaulter.config_validator.documents', ConfigValidCheck::TAG);
        ConfigSectionRegistration::registerValidator($app, 'vaulter.documents', $validate);

        $app->scoped(DocumentTypeRegistry::class);
        $app->scoped(DocumentExporterRegistry::class);
        $app->scoped(DocumentsManager::class);
        $app->bind(DocumentOperations::class);
        $app->bind(CommentOperations::class);
        $app->bind(AtomicDocumentMutation::class, RunAtomicDocumentMutation::class);
        $app->alias(DocumentsManager::class, 'vaulter.documents');
        $app->scoped(CollaborativeState::class, DatabaseCollaborativeState::class);

        $app->scoped(HtmlSanitizerInterface::class, static function (): HtmlSanitizer {
            $config = (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowLinkSchemes(['https', 'http', 'mailto'])
                ->allowMediaSchemes(['https', 'http']);

            return new HtmlSanitizer($config);
        });
    }

    public function boot(Application $app): void
    {
        $options = self::buildOptions($app);
        $database = $app->make(DatabaseOptions::class);
        Document::configure($database, self::buildDriveOptions($app), $options);
        DocumentPayload::configure($database);
        DocumentSyncState::configure($database);
        DocumentRevisionPayload::configure($database);
        DocumentRevisionSyncState::configure($database);
        // Core reads a document head through this relation without depending on
        // the documents package; subclasses of the configured node model inherit it.
        Node::resolveRelationUsing('document', static fn (Node $node): HasOne => $node->hasOne(Document::class, 'node_id'));

        self::bootDocumentTypes($app, $options);
        self::bootDocumentExporters($app, $options);
    }

    private static function buildOptions(Container $app): DocumentsOptions
    {
        $raw = $app->bound(Repository::class) ? $app->make(Repository::class)->get('vaulter-documents', []) : [];

        return DocumentsOptions::fromArray(is_array($raw) ? $raw : ['__invalid_root' => $raw], 'vaulter-documents');
    }

    private static function buildDriveOptions(Container $app): DriveOptions
    {
        $raw = $app->bound(Repository::class) ? $app->make(Repository::class)->get('vaulter-drive', []) : [];

        return DriveOptions::fromArray(is_array($raw) ? $raw : [], 'vaulter-drive');
    }

    /**
     * The modules' contributions, config and tagged types join the ones providers registered; the set freezes once
     * every provider has booted, and only then can the default type be checked.
     */
    private static function bootDocumentTypes(Application $app, DocumentsOptions $options): void
    {
        $types = $app->make(DocumentTypeRegistry::class);
        $contributed = [];
        foreach ($app->make(Kernel::class)->extensions()->contributions(DocumentType::class) as $contribution) {
            $types->register($contribution->implementation);
            $contributed[$contribution->implementation] = true;
        }
        foreach ($options->types as $class) {
            if (!isset($contributed[$class])) {
                $types->register($class);
            }
        }
        $tagged = [];
        foreach ($app->tagged('vaulter.document_types') as $type) {
            if (!$type instanceof DocumentType && !is_string($type)) {
                throw new InvalidConfigurationException(sprintf('[Vaulter] Document type "%s" did not resolve to a %s.', get_debug_type($type), DocumentType::class));
            }
            $types->register($type);
            if ($type instanceof DocumentType) {
                $tagged[] = $type;
            }
        }

        $app->booted(static function () use ($app, $types, $tagged): void {
            $types->freeze();
            $app->scoped(DocumentTypeRegistry::class, $types->factoryForWorkerScopes($tagged));
            $default = self::buildOptions($app)->defaultType;
            if (!$types->has($default)) {
                throw new InvalidConfigurationException(sprintf('[Vaulter] vaulter-documents.default_type "%s" is not a registered document type.', $default));
            }
        });
    }

    /** The modules' contributions, config and tagged exporters join the ones providers registered; the set freezes after boot. */
    private static function bootDocumentExporters(Application $app, DocumentsOptions $options): void
    {
        $exporters = $app->make(DocumentExporterRegistry::class);
        $contributed = [];
        foreach ($app->make(Kernel::class)->extensions()->contributions(DocumentExporter::class) as $contribution) {
            $exporters->register($contribution->implementation);
            $contributed[$contribution->implementation] = true;
        }
        foreach ($options->exporters as $class) {
            if (!isset($contributed[$class])) {
                $exporters->register($class);
            }
        }
        $tagged = [];
        foreach ($app->tagged('vaulter.exporters') as $exporter) {
            if (!$exporter instanceof DocumentExporter && !is_string($exporter)) {
                throw new InvalidConfigurationException(sprintf('[Vaulter] Document exporter "%s" did not resolve to a %s.', get_debug_type($exporter), DocumentExporter::class));
            }
            $exporters->register($exporter);
            if ($exporter instanceof DocumentExporter) {
                $tagged[] = $exporter;
            }
        }

        $app->booted(static function () use ($app, $exporters, $tagged): void {
            $exporters->freeze();
            $app->scoped(DocumentExporterRegistry::class, $exporters->factoryForWorkerScopes($tagged));
        });
    }
}
