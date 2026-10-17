<?php

declare(strict_types=1);

namespace Vaulter\Collaboration;

use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\Console\PruneGenerationsCommand;
use Vaulter\Collaboration\Doctor\CollaborationCheck;
use Vaulter\Collaboration\Drain\HubDrainer;
use Vaulter\Collaboration\Http\Support\CollaborationRouteLabels;
use Vaulter\Collaboration\Operations\CollaborationOperations;
use Vaulter\Collaboration\Persistence\GenerationCloser;
use Vaulter\Collaboration\Providers\CollaborationRegistrar;
use Vaulter\Collaboration\Schema\CollaborationSchema;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Contracts\Routing\RouteLabelSource;
use Vaulter\Documents\Contracts\CollaborationDriver;
use Vaulter\Documents\State\PendingStateDrainer;
use Vaulter\Drive\Contracts\DriveExtension;
use Vaulter\Events\EventSink;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\OwnsSchema;
use Vaulter\Modules\VaulterModule;

/**
 * The collaboration module (D1, D22): the manifest of the vaulter-collaboration package, offered by its provider.
 * Opt-in, because it opens a network channel and needs secrets; it edits documents, so it requires the documents
 * module. It provides the `CollaborationDriver` and `PendingStateDrainer` capabilities the documents module defines (the
 * realtime transport and the drain of a pending document through the hub), and brings its session and hub routes,
 * problem codes, doctor check, generation closing and prune, and `vaulter-collaboration.*` configuration.
 */
#[Internal]
final class CollaborationModule implements OwnsSchema, VaulterModule
{
    public static function ownedSchema(): string
    {
        return CollaborationSchema::class;
    }

    public function id(): ModuleId
    {
        return new ModuleId('collaboration');
    }

    public function version(): string
    {
        return '1.0';
    }

    public function activation(): Activation
    {
        return Activation::OptIn;
    }

    public function requires(): array
    {
        return ['core' => '^1.0', 'drive' => '^1.0', 'documents' => '^1.0'];
    }

    public function register(ModuleBuilder $module): void
    {
        $module
            ->config('vaulter-collaboration', dirname(__DIR__).'/config/vaulter-collaboration.php')
            // The update log, receipts and heads of a document generation (D26); installed with the package, kept when off.
            ->schema(dirname(__DIR__).'/database/migrations')
            ->routes(dirname(__DIR__).'/routes/collaboration.php')
            ->problems(CollaborationProblemCodes::class)
            ->bindings(CollaborationRegistrar::class)
            ->commands(PruneGenerationsCommand::class)
            ->schedule(CollaborationSchedule::class)
            ->slot(RouteLabelSource::class, CollaborationRouteLabels::class, 'collaboration.routes')
            ->slot(DoctorCheck::class, CollaborationCheck::class, 'collaboration.hub', key: 'collaboration.hub')
            // The session is an operation of the documents handle's drive: `$drive->extension(CollaborationOperations::class)`.
            ->slot(DriveExtension::class, CollaborationOperations::class, 'collaboration.operations', key: CollaborationOperations::class)
            // A restore or REST replace that moves a document's generation on closes the old generation's log in its transaction.
            ->slot(EventSink::class, GenerationCloser::class, 'collaboration.generations')
            // The transport is the capability the documents module defines (OptionalSingle): without this module none is selected.
            ->provides(CollaborationDriver::class, HocuspocusCollaborationDriver::class)
            // A pending document is drained through the hub's compaction worker before documents reads or replaces it (C8, C9).
            ->provides(PendingStateDrainer::class, HubDrainer::class);
    }
}
