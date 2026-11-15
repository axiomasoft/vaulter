<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule;

use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Drive\Contracts\DownloadGuard;
use Vaulter\Drive\Contracts\NodeSnapshotContributor;
use Vaulter\Events\EventSink;
use Vaulter\Events\Outbox\OutboxConsumer;
use Vaulter\Modules\Activation;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\SlotKind;
use Vaulter\Modules\VaulterModule;
use Vaulter\Operations\OperationMiddleware;

/**
 * A third-party module written against the public module surface only: it owns a config root, a table,
 * a route, a command, a listener and container wiring, defines a slot of its own (a Restriction one, which the
 * kernel has no built-in for) and contributes to the core and drive slots. Opt-in, like every module that
 * opens a new surface.
 */
final class AcmeModule implements VaulterModule
{
    public function id(): ModuleId
    {
        return new ModuleId('acme');
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
        return ['core' => '^1.0', 'drive' => '^1.0'];
    }

    public function register(ModuleBuilder $module): void
    {
        $module
            ->config('vaulter-acme', __DIR__.'/config.php')
            ->schema(__DIR__.'/migrations')
            ->routes(__DIR__.'/routes.php')
            ->operations(AcmeOperationPolicies::class)
            ->commands(AcmeCommand::class)
            ->listen(AcmePinged::class, AcmeListener::class)
            ->bindings(AcmeBindings::class)
            ->defineSlot(AcmeRestriction::class, SlotKind::Restriction, Cardinality::Many)
            ->slot(AcmeRestriction::class, AcmeNarrowing::class, 'acme.narrowing')
            ->slot(OperationMiddleware::class, AcmeMiddleware::class, 'acme.middleware', priority: 10)
            ->slot(EventSink::class, AcmeEventSink::class, 'acme.sink', priority: 50)
            ->slot(OutboxConsumer::class, AcmeOutboxConsumer::class, AcmeOutboxConsumer::ID)
            ->slot(DoctorCheck::class, AcmeDoctorCheck::class, 'acme.endpoint', key: 'acme.endpoint')
            ->slot(DownloadGuard::class, AcmeDownloadGuard::class, 'acme.download-guard', priority: -5)
            ->slot(NodeSnapshotContributor::class, AcmeSnapshotContributor::class, 'acme.snapshot');
    }
}
