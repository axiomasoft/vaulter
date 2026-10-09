<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Spatie\LaravelPackageTools\Package;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Drive\Activity\RecordsDriveActivity;
use Vaulter\Drive\Console\PruneRevisionsCommand;
use Vaulter\Drive\Console\PurgeDrivesCommand;
use Vaulter\Drive\Console\PurgeTrashCommand;
use Vaulter\Drive\Console\RecountDrivesCommand;
use Vaulter\Drive\Console\SweepUploadsCommand;
use Vaulter\Drive\Webhooks\RecordsDriveOutbox;

final class HttpRegistrar
{
    public function configure(Package $package): void
    {
        $package
            ->hasRoute('drive')
            ->hasCommand(PurgeTrashCommand::class)
            ->hasCommand(PurgeDrivesCommand::class)
            ->hasCommand(RecountDrivesCommand::class)
            ->hasCommand(PruneRevisionsCommand::class)
            ->hasCommand(SweepUploadsCommand::class);
    }

    public function boot(Application $app): void
    {
        if (app(VaulterConfig::class)->featureEnabled('activity')) {
            $app->make(Dispatcher::class)->subscribe(RecordsDriveActivity::class);
        }

        if (app(VaulterConfig::class)->featureEnabled('webhooks')) {
            $app->make(Dispatcher::class)->subscribe(RecordsDriveOutbox::class);
        }
    }
}
