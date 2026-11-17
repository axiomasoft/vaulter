<?php

declare(strict_types=1);

namespace Vaulter\Documents;

use Override;
use Packman\Attributes\Api;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Enums\Contributions\SlotKind;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\Contracts\Routing\RouteLabelSource;
use Vaulter\Documents\Configuration\DocumentsOptions;
use Vaulter\Documents\Content\DocumentContentLifecycle;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\Contracts\DocumentExporter;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Contracts\ExportFilenameResolver;
use Vaulter\Documents\Doctor\DocumentTypesCheck;
use Vaulter\Documents\Errors\DocumentsProblemDefinitions;
use Vaulter\Documents\Export\DefaultExportFilenameResolver;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Http\Support\DocumentsRouteLabels;
use Vaulter\Documents\Operations\CommentOperations;
use Vaulter\Documents\Operations\DocumentOperations;
use Vaulter\Documents\Presentation\DocumentPresenter;
use Vaulter\Documents\Profiles\AllowedDocumentTypesProfileOption;
use Vaulter\Documents\Profiles\CommentsProfileOption;
use Vaulter\Documents\Schema\DocumentsSchema;
use Vaulter\Documents\Schema\DocumentsSchemaSets;
use Vaulter\Documents\State\PendingPurgeDrain;
use Vaulter\Documents\State\PendingStateDrainer;
use Vaulter\Documents\Support\DocumentHeads;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Drive\Contracts\DriveExtension;
use Vaulter\Drive\Contracts\NodeContentLifecycle;
use Vaulter\Drive\Contracts\NodeSnapshotContributor;
use Vaulter\Drive\Contracts\Presentation\KindPresenter;
use Vaulter\Drive\Contracts\Profiles\ProfileOption;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Operations\OperationMiddleware;

/**
 * The documents extension plugin (`vaulter.documents`): rich-text/spreadsheet documents,
 * revisions, threaded comments, export capabilities, error catalog, schema sets, and diagnostics.
 */
#[Api(since: '1.0')]
final class DocumentsModule implements ExtensionPlugin, WithCapabilities, WithConfiguration, WithDiagnostics, WithSchema, WithSlots
{
    use Configurable;
    use ContributesToSlots;
    use OwnsSchema;
    use ProvidesCapabilities;
    use ProvidesDiagnostics;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'vaulter.documents',
            version: '1.0.0',
            requires: ['vaulter.core', 'vaulter.drive'],
            resourceRoot: dirname(__DIR__).'/resources',
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void {}

    #[Override]
    public function configRoot(): string
    {
        return 'vaulter-documents';
    }

    #[Override]
    public function defaultConfigPath(): string
    {
        return dirname(__DIR__).'/config/vaulter-documents.php';
    }

    #[Override]
    public function optionsClass(): string
    {
        return DocumentsOptions::class;
    }

    #[Override]
    public function tablePrefix(): string
    {
        return 'v_';
    }

    /** @return list<string> */
    #[Override]
    public function tableNouns(): array
    {
        return ['node_comments', 'documents', 'document_revisions'];
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__).'/database/migrations';
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaSetProviders(): array
    {
        return [DocumentsSchemaSets::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaComponents(): array
    {
        return [DocumentsSchema::class];
    }

    /** @return array<class-string, string> */
    #[Override]
    public function definedCapabilities(): array
    {
        return [
            ExportFilenameResolver::class => Cardinality::RequiredSingle->value,
        ];
    }

    /** @return array<class-string, class-string|array{config: string, default?: class-string|null}> */
    #[Override]
    public function providedCapabilities(): array
    {
        return [
            ExportFilenameResolver::class => DefaultExportFilenameResolver::class,
        ];
    }

    /** @return array<class-string, array{kind: string, cardinality?: string}> */
    #[Override]
    public function definedSlots(): array
    {
        $handler = ['kind' => SlotKind::Handler->value, 'cardinality' => Cardinality::Many->value];

        return [
            DocumentType::class => $handler,
            DocumentExporter::class => $handler,
            CollaborationProtocolDriver::class => $handler,
            PendingStateDrainer::class => $handler,
        ];
    }

    /** @return list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}> */
    #[Override]
    public function slotContributions(): array
    {
        return [
            ['slot' => RouteLabelSource::class, 'id' => 'documents.routes', 'implementation' => DocumentsRouteLabels::class],
            ['slot' => DoctorCheck::class, 'id' => 'documents.types', 'implementation' => DocumentTypesCheck::class, 'key' => 'documents.types'],
            ['slot' => NodeSnapshotContributor::class, 'id' => 'documents.heads', 'implementation' => DocumentHeads::class, 'key' => DocumentHeads::KEY],
            ['slot' => DriveExtension::class, 'id' => 'documents.operations', 'implementation' => DocumentOperations::class, 'key' => DocumentOperations::class],
            ['slot' => DriveExtension::class, 'id' => 'documents.comments', 'implementation' => CommentOperations::class, 'key' => CommentOperations::class],
            ['slot' => KindPresenter::class, 'id' => 'documents.presenter', 'implementation' => DocumentPresenter::class, 'key' => NodeKind::Document->value],
            ['slot' => NodeContentLifecycle::class, 'id' => 'documents.lifecycle', 'implementation' => DocumentContentLifecycle::class, 'key' => NodeKind::Document->value],
            ['slot' => OperationMiddleware::class, 'id' => 'documents.pending-purge-drain', 'implementation' => PendingPurgeDrain::class],
            ['slot' => ProfileOption::class, 'id' => 'documents.profile.comments', 'implementation' => CommentsProfileOption::class, 'key' => CommentsProfileOption::NAME],
            ['slot' => ProfileOption::class, 'id' => 'documents.profile.allowed-document-types', 'implementation' => AllowedDocumentTypesProfileOption::class, 'key' => AllowedDocumentTypesProfileOption::NAME],
            ['slot' => DocumentType::class, 'id' => 'documents.type.rich-text', 'implementation' => RichTextDocumentType::class, 'key' => RichTextDocumentType::KEY],
            ['slot' => DocumentType::class, 'id' => 'documents.type.markdown', 'implementation' => MarkdownDocumentType::class, 'key' => MarkdownDocumentType::KEY],
            ['slot' => DocumentExporter::class, 'id' => 'documents.exporter.html', 'implementation' => HtmlExporter::class, 'key' => 'vaulter/html'],
            ['slot' => DocumentExporter::class, 'id' => 'documents.exporter.markdown', 'implementation' => MarkdownExporter::class, 'key' => 'vaulter/markdown'],
        ];
    }

    /** @return list<class-string> */
    #[Override]
    public function healthChecks(): array
    {
        return [DocumentTypesCheck::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [DocumentsProblemDefinitions::class];
    }
}
