<?php

declare(strict_types=1);

namespace Vaulter\Collaboration;

use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\Console\DrainCommand;
use Vaulter\Collaboration\Console\PruneGenerationsCommand;
use Vaulter\Collaboration\Doctor\CollaborationCheck;
use Vaulter\Collaboration\Drain\HubDrainer;
use Vaulter\Collaboration\Drain\PendingRemovalGuard;
use Vaulter\Collaboration\Http\Support\CollaborationRouteLabels;
use Vaulter\Collaboration\Operations\CollaborationOperationPolicies;
use Vaulter\Collaboration\Persistence\GenerationCloser;
use Vaulter\Collaboration\Providers\CollaborationRegistrar;
use Vaulter\Collaboration\Revocation\ControlConsumer;
use Vaulter\Collaboration\Revocation\PermissionVersionSink;
use Vaulter\Collaboration\Schema\CollaborationSchema;
use Vaulter\Collaboration\Security\HubUrlInvariant;
use Vaulter\Collaboration\Security\SecretsInvariant;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Contracts\Routing\RouteLabelSource;
use Vaulter\Contracts\Security\ProductionInvariant;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\State\PendingStateDrainer;
use Vaulter\Events\EventSink;
use Vaulter\Events\Outbox\OutboxConsumer;
use Vaulter\Modules\Activation;
use Vaulter\Modules\GuardsRemoval;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\OwnsSchema;
use Vaulter\Modules\VaulterModule;

/**
 * The collaboration module: the manifest of the vaulter-collaboration package, offered by its provider.
 * Opt-in, because it opens a network channel and needs secrets; it edits documents, so it requires the documents
 * module. It provides the `CollaborationProtocolDriver` and `PendingStateDrainer` capabilities the documents module defines (the
 * realtime transport and the drain of a pending document through the hub), and brings its session and hub routes,
 * problem codes, doctor check, generation closing and prune, the managed drain, the permission versions and control
 * channel of revocations, and `vaulter-collaboration.*` configuration. Its removal is refused while a document carries saved edits not in a checkpoint yet.
 */
#[Internal]
final class CollaborationModule implements GuardsRemoval, OwnsSchema, VaulterModule
{
    public static function ownedSchema(): string
    {
        return CollaborationSchema::class;
    }

    /** The documents with uncheckpointed edits refuse `vaulter:module:uninstall collaboration` (C10). */
    public static function removalGuard(): string
    {
        return PendingRemovalGuard::class;
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
            // The update log, receipts and heads of a document generation and the permission versions; installed
            // with the package, kept when off.
            ->schema(dirname(__DIR__).'/database/migrations')
            ->routes(dirname(__DIR__).'/routes/collaboration.php')
            ->problems(CollaborationProblemCodes::class)
            ->operations(CollaborationOperationPolicies::class)
            ->bindings(CollaborationRegistrar::class)
            ->commands(PruneGenerationsCommand::class, DrainCommand::class)
            ->schedule(CollaborationSchedule::class)
            ->slot(RouteLabelSource::class, CollaborationRouteLabels::class, 'collaboration.routes')
            ->slot(DoctorCheck::class, CollaborationCheck::class, 'collaboration.hub', key: 'collaboration.hub')
            ->slot(ProductionInvariant::class, SecretsInvariant::class, 'collaboration.secrets', key: 'collaboration.secrets')
            ->slot(ProductionInvariant::class, HubUrlInvariant::class, 'collaboration.hub-url', key: 'collaboration.hub-url')
            // A restore or REST replace that moves a document's generation on closes the old generation's log in its transaction.
            ->slot(EventSink::class, GenerationCloser::class, 'collaboration.generations')
            // A change of effective permissions counts in the version of its drive (or the global one) in its transaction…
            ->slot(EventSink::class, PermissionVersionSink::class, PermissionVersionSink::ID)
            // …and reaches the hubs after its commit, best effort (ephemeral: no retention, no replay).
            ->slot(OutboxConsumer::class, ControlConsumer::class, ControlConsumer::ID)
            // The transport is the capability the documents module defines (OptionalSingle): without this module none is selected.
            ->slot(CollaborationProtocolDriver::class, HocuspocusCollaborationDriver::class, 'collaboration.protocol.yjs', key: 'yjs')
            // A pending document is drained through the hub's compaction worker before documents reads or replaces it (C8, C9).
            ->slot(PendingStateDrainer::class, HubDrainer::class, 'collaboration.pending.yjs', key: 'yjs');
    }
}
