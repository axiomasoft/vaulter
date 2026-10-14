<?php

declare(strict_types=1);

namespace Vaulter\Documents;

use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Contracts\Drive\DriveExtension;
use Vaulter\Contracts\Drive\NodeContentLifecycle;
use Vaulter\Contracts\Drive\NodeSnapshotContributor;
use Vaulter\Contracts\Presentation\KindPresenter;
use Vaulter\Contracts\Profiles\ProfileOption;
use Vaulter\Documents\Collaboration\HocuspocusCollaborationDriver;
use Vaulter\Documents\Console\MakeDocumentTypeCommand;
use Vaulter\Documents\Console\MakeExporterCommand;
use Vaulter\Documents\Content\DocumentContentLifecycle;
use Vaulter\Documents\Contracts\CollaborationDriver;
use Vaulter\Documents\Contracts\DocumentExporter;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Contracts\ExportFilenameResolver;
use Vaulter\Documents\Doctor\CollaborationCheck;
use Vaulter\Documents\Doctor\DocumentTypesCheck;
use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Documents\Export\DefaultExportFilenameResolver;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Http\Problems\DocumentsProblemCodes;
use Vaulter\Documents\Operations\CommentOperations;
use Vaulter\Documents\Operations\DocumentOperations;
use Vaulter\Documents\Presentation\DocumentPresenter;
use Vaulter\Documents\Profiles\AllowedDocumentTypesProfileOption;
use Vaulter\Documents\Profiles\CommentsProfileOption;
use Vaulter\Documents\Providers\BindingsRegistrar;
use Vaulter\Documents\Providers\CollaborationRegistrar;
use Vaulter\Documents\Support\DocumentHeads;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Modules\Activation;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\SlotKind;
use Vaulter\Modules\VaulterModule;

/**
 * The documents module (D22): document types, revisions, comments and export, their routes and commands,
 * and — until collaboration becomes its own module (P5, D32) — the collaboration routes, still mounted by
 * the existing `vaulter-documents.collaboration.enabled` switch inside the routes file.
 */
final class DocumentsModule implements VaulterModule
{
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
            ->routes(dirname(__DIR__).'/routes/documents.php')
            ->problems(DocumentsProblemCodes::class)
            ->events(DocumentEventType::class)
            ->bindings(BindingsRegistrar::class)
            ->bindings(CollaborationRegistrar::class)
            ->commands(MakeDocumentTypeCommand::class, MakeExporterCommand::class)
            ->slot(DoctorCheck::class, DocumentTypesCheck::class, 'documents.types', key: 'documents.types')
            ->slot(DoctorCheck::class, CollaborationCheck::class, 'documents.collaboration', key: 'documents.collaboration')
            // The drive's node snapshots carry the `document` fragment; a handle serves the document and comment operations.
            ->slot(NodeSnapshotContributor::class, DocumentHeads::class, 'documents.heads', key: DocumentHeads::KEY)
            ->slot(DriveExtension::class, DocumentOperations::class, 'documents.operations', key: DocumentOperations::class)
            ->slot(DriveExtension::class, CommentOperations::class, 'documents.comments', key: CommentOperations::class)
            // Documents are presented and copied/purged by the documents module, not by the file machinery.
            ->slot(KindPresenter::class, DocumentPresenter::class, 'documents.presenter', key: NodeKind::Document->value)
            ->slot(NodeContentLifecycle::class, DocumentContentLifecycle::class, 'documents.lifecycle', key: NodeKind::Document->value)
            ->slot(ProfileOption::class, CommentsProfileOption::class, 'documents.profile.comments', key: CommentsProfileOption::NAME)
            ->slot(ProfileOption::class, AllowedDocumentTypesProfileOption::class, 'documents.profile.allowed-document-types', key: AllowedDocumentTypesProfileOption::NAME)
            // The slots the module defines (D8) and its built-in contributions; a host adds its own through config class-strings.
            ->defineSlot(DocumentType::class, SlotKind::Handler, Cardinality::Many)
            ->defineSlot(DocumentExporter::class, SlotKind::Handler, Cardinality::Many)
            ->slot(DocumentType::class, RichTextDocumentType::class, 'documents.type.rich-text', key: RichTextDocumentType::KEY)
            ->slot(DocumentType::class, MarkdownDocumentType::class, 'documents.type.markdown', key: MarkdownDocumentType::KEY)
            ->slot(DocumentExporter::class, HtmlExporter::class, 'documents.exporter.html', key: 'vaulter/html')
            ->slot(DocumentExporter::class, MarkdownExporter::class, 'documents.exporter.markdown', key: 'vaulter/markdown')
            // The export filename scheme and the realtime transport are capabilities; collaboration itself stays
            // switched by `vaulter-documents.collaboration.enabled` until it becomes a module of its own (P5, D32).
            ->defineCapability(ExportFilenameResolver::class, Cardinality::RequiredSingle)
            ->provides(ExportFilenameResolver::class, DefaultExportFilenameResolver::class)
            ->defineCapability(CollaborationDriver::class, Cardinality::OptionalSingle)
            ->provides(CollaborationDriver::class, HocuspocusCollaborationDriver::class);
    }
}
