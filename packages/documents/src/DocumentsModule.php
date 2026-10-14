<?php

declare(strict_types=1);

namespace Vaulter\Documents;

use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Documents\Console\MakeDocumentTypeCommand;
use Vaulter\Documents\Console\MakeExporterCommand;
use Vaulter\Documents\Doctor\CollaborationCheck;
use Vaulter\Documents\Doctor\DocumentTypesCheck;
use Vaulter\Documents\Providers\BindingsRegistrar;
use Vaulter\Documents\Providers\CollaborationRegistrar;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
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
            ->bindings(BindingsRegistrar::class)
            ->bindings(CollaborationRegistrar::class)
            ->commands(MakeDocumentTypeCommand::class, MakeExporterCommand::class)
            ->slot(DoctorCheck::class, DocumentTypesCheck::class, 'documents.types', key: 'documents.types')
            ->slot(DoctorCheck::class, CollaborationCheck::class, 'documents.collaboration', key: 'documents.collaboration');
    }
}
