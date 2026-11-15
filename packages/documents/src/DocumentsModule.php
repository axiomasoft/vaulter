<?php

declare(strict_types=1);

namespace Vaulter\Documents;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Contracts\Routing\RouteLabelSource;
use Vaulter\Documents\Console\MakeDocumentTypeCommand;
use Vaulter\Documents\Console\MakeExporterCommand;
use Vaulter\Documents\Content\DocumentContentLifecycle;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\Contracts\DocumentExporter;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Contracts\ExportFilenameResolver;
use Vaulter\Documents\Doctor\DocumentTypesCheck;
use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Documents\Export\DefaultExportFilenameResolver;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Http\Problems\DocumentsProblemCodes;
use Vaulter\Documents\Http\Support\DocumentsRouteLabels;
use Vaulter\Documents\Operations\CommentOperations;
use Vaulter\Documents\Operations\DocumentOperations;
use Vaulter\Documents\Operations\DocumentsOperationPolicies;
use Vaulter\Documents\Presentation\DocumentPresenter;
use Vaulter\Documents\Profiles\AllowedDocumentTypesProfileOption;
use Vaulter\Documents\Profiles\CommentsProfileOption;
use Vaulter\Documents\Providers\BindingsRegistrar;
use Vaulter\Documents\Schema\DocumentsSchema;
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
use Vaulter\Modules\Activation;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\OwnsSchema;
use Vaulter\Modules\SlotKind;
use Vaulter\Modules\VaulterModule;
use Vaulter\Operations\OperationMiddleware;

/**
 * The documents module: document types, revisions, comments and export, their routes and commands. The
 * realtime transport is a capability it defines and the collaboration module provides.
 */
#[Internal]
final class DocumentsModule implements OwnsSchema, VaulterModule
{
    public static function ownedSchema(): string
    {
        return DocumentsSchema::class;
    }

    public function id(): ModuleId
    {
        return new ModuleId('documents');
    }

    public function version(): string
    {
        return '1.0';
    }

    public function activation(): Activation
    {
        return Activation::Auto;
    }

    public function requires(): array
    {
        return ['core' => '^1.0', 'drive' => '^1.0'];
    }

    public function register(ModuleBuilder $module): void
    {
        $module
            ->config('vaulter-documents', dirname(__DIR__).'/config/vaulter-documents.php')
            ->schema(dirname(__DIR__).'/database/migrations')
            ->routes(dirname(__DIR__).'/routes/documents.php')
            ->problems(DocumentsProblemCodes::class)
            ->operations(DocumentsOperationPolicies::class)
            ->events(DocumentEventType::class)
            ->bindings(BindingsRegistrar::class)
            ->commands(MakeDocumentTypeCommand::class, MakeExporterCommand::class)
            ->slot(RouteLabelSource::class, DocumentsRouteLabels::class, 'documents.routes')
            ->slot(DoctorCheck::class, DocumentTypesCheck::class, 'documents.types', key: 'documents.types')
            // The drive's node snapshots carry the `document` fragment; a handle serves the document and comment operations.
            ->slot(NodeSnapshotContributor::class, DocumentHeads::class, 'documents.heads', key: DocumentHeads::KEY)
            ->slot(DriveExtension::class, DocumentOperations::class, 'documents.operations', key: DocumentOperations::class)
            ->slot(DriveExtension::class, CommentOperations::class, 'documents.comments', key: CommentOperations::class)
            // Documents are presented and copied/purged by the documents module, not by the file machinery.
            ->slot(KindPresenter::class, DocumentPresenter::class, 'documents.presenter', key: NodeKind::Document->value)
            ->slot(NodeContentLifecycle::class, DocumentContentLifecycle::class, 'documents.lifecycle', key: NodeKind::Document->value)
            // A trash purge drains pending documents before its transaction; the lifecycle re-checks the marker under lock.
            ->slot(OperationMiddleware::class, PendingPurgeDrain::class, 'documents.pending-purge-drain')
            ->slot(ProfileOption::class, CommentsProfileOption::class, 'documents.profile.comments', key: CommentsProfileOption::NAME)
            ->slot(ProfileOption::class, AllowedDocumentTypesProfileOption::class, 'documents.profile.allowed-document-types', key: AllowedDocumentTypesProfileOption::NAME)
            // The slots the module defines and its built-in contributions; a host adds its own through config class-strings.
            ->defineSlot(DocumentType::class, SlotKind::Handler, Cardinality::Many)
            ->defineSlot(DocumentExporter::class, SlotKind::Handler, Cardinality::Many)
            ->slot(DocumentType::class, RichTextDocumentType::class, 'documents.type.rich-text', key: RichTextDocumentType::KEY)
            ->slot(DocumentType::class, MarkdownDocumentType::class, 'documents.type.markdown', key: MarkdownDocumentType::KEY)
            ->slot(DocumentExporter::class, HtmlExporter::class, 'documents.exporter.html', key: 'vaulter/html')
            ->slot(DocumentExporter::class, MarkdownExporter::class, 'documents.exporter.markdown', key: 'vaulter/markdown')
            // The export filename scheme and the realtime transport are capabilities. The transport is optional: the
            // collaboration module (a package of its own) provides it, and without it documents are edited one at a time.
            ->defineCapability(ExportFilenameResolver::class, Cardinality::RequiredSingle)
            ->provides(ExportFilenameResolver::class, DefaultExportFilenameResolver::class)
            ->defineSlot(CollaborationProtocolDriver::class, SlotKind::Handler, Cardinality::Many)
            // Brings a pending document's working state up to the collaboration log: without a provider such a
            // document answers 409 document_state_pending instead of serving or replacing stale content.
            ->defineSlot(PendingStateDrainer::class, SlotKind::Handler, Cardinality::Many);
    }
}
