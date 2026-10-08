<?php

declare(strict_types=1);

namespace Vaulter\Documents\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Contracts\Drive\NodeDocumentHeads;
use Vaulter\Contracts\Profiles\CommentsFeature;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Content\DocumentContentLifecycle;
use Vaulter\Documents\Contracts\ExportFilenameResolver;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Export\DefaultExportFilenameResolver;
use Vaulter\Documents\Operations\CommentOperations;
use Vaulter\Documents\Operations\DocumentOperations;
use Vaulter\Documents\Presentation\DocumentPresenter;
use Vaulter\Documents\Registries\DocumentExporterRegistry;
use Vaulter\Documents\Support\DocumentHeads;
use Vaulter\Documents\Types\DocumentTypeRegistry;

final class BindingsRegistrar extends ServiceProvider
{
    public function register(): void
    {
        $path = __DIR__.'/../../config/vaulter-documents.php';
        $this->mergeConfigFrom(path: $path, key: 'vaulter-documents');
        $this->app->singleton(DocumentsConfig::class, fn (Container $app): DocumentsConfig => new DocumentsConfig($app->make('config')));
        $this->app->bind(CommentsFeature::class, DocumentsConfig::class);
        $this->app->singletonIf(ConfigNormalizer::class);
        $this->app->make(ConfigNormalizer::class)->registerDefaults(root: 'vaulter-documents', path: $path);

        $this->app->singleton(DocumentTypeRegistry::class);
        $this->app->singleton(DocumentExporterRegistry::class);
        $this->app->singleton(DocumentsManager::class);
        // Operations carry the handle's context, so every resolution is a new instance.
        $this->app->bind(DocumentOperations::class);
        $this->app->bind(CommentOperations::class);
        $this->app->bind(NodeDocumentHeads::class, DocumentHeads::class);
        $this->app->alias(DocumentsManager::class, 'vaulter.documents');
        // Drive's registry collects this tag and prefers it to its own purge-only stand-in.
        $this->app->tag([DocumentContentLifecycle::class], 'vaulter.content_lifecycles');
        // Core presents document nodes through this tag; files keep the file handlers.
        $this->app->tag([DocumentPresenter::class], 'vaulter.kind_presenters');

        // The export filename seam (P4.5 / F-P3.1-04): NodeExportService stays
        // `final` (H.5 arch gate), so a host wanting a different export filename
        // scheme rebinds this contract to its own class instead of forking the
        // service. Bound to the pre-P4.5 hardcoded scheme (node basename + exporter
        // extension) — default behaviour is unchanged.
        $this->app->bind(ExportFilenameResolver::class, DefaultExportFilenameResolver::class);

        // Second echelon of the F-P1.11-01 stored-XSS fix (D4): TipTapRenderer takes
        // the sanitizer through its constructor (never resolved inline from the
        // container) so every HTML render — including content persisted before the
        // store-time validator existed — is sanitized on the way out. Singleton: the
        // sanitizer is stateless and config-immutable per request.
        $this->app->singleton(HtmlSanitizerInterface::class, static function (): HtmlSanitizer {
            $config = (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowLinkSchemes(['https', 'http', 'mailto'])
                ->allowMediaSchemes(['https', 'http']);

            return new HtmlSanitizer($config);
        });
    }
}
