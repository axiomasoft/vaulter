<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Spatie\LaravelPackageTools\Package;
use Vaulter\Drive\Activity\RecordsDriveActivity;
use Vaulter\Drive\Console\PurgeTrashCommand;
use Vaulter\Drive\Console\SweepUploadsCommand;
use Vaulter\Drive\Webhooks\RecordsDriveOutbox;
use Vaulter\Support\Config;

final class HttpRegistrar
{
    public function configure(Package $package): void
    {
        $package
            ->hasRoute('drive')
            ->hasCommand(PurgeTrashCommand::class)
            ->hasCommand(SweepUploadsCommand::class);
    }

    public function boot(Application $app): void
    {
        if (Config::featureEnabled('activity')) {
            $app->make(Dispatcher::class)->subscribe(RecordsDriveActivity::class);
        }

        if (Config::featureEnabled('reliability')) {
            $app->make(Dispatcher::class)->subscribe(RecordsDriveOutbox::class);
        }
    }
}
