<?php

declare(strict_types=1);

namespace Vaulter\Providers;

use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Foundation\Application;
use Vaulter\Console\CommandsReferenceCommand;
use Vaulter\Console\ConfigReferenceCommand;
use Vaulter\Console\DoctorCommand;
use Vaulter\Console\ErrorsReferenceCommand;
use Vaulter\Console\EventsReferenceCommand;
use Vaulter\Console\InstallCommand;
use Vaulter\Console\MakeFileHandlerCommand;
use Vaulter\Console\MakePermissionDriverCommand;
use Vaulter\Console\MakeProfileCommand;
use Vaulter\Console\PruneChangesCommand;
use Vaulter\Console\PruneWebhooksCommand;
use Vaulter\Console\RedeliverWebhooksCommand;
use Vaulter\Console\RelayWebhooksCommand;
use Vaulter\Console\ResetChangesCommand;
use Vaulter\Console\RetryWebhooksCommand;

final class ConsoleRegistrar
{
    public function register(Application $app): void
    {
        Artisan::starting(static function (Artisan $artisan): void {
            $artisan->resolveCommands([
                MakeProfileCommand::class,
                MakeFileHandlerCommand::class,
                MakePermissionDriverCommand::class,
                EventsReferenceCommand::class,
                ErrorsReferenceCommand::class,
                ConfigReferenceCommand::class,
                CommandsReferenceCommand::class,
                RelayWebhooksCommand::class,
                RetryWebhooksCommand::class,
                PruneWebhooksCommand::class,
                RedeliverWebhooksCommand::class,
                PruneChangesCommand::class,
                ResetChangesCommand::class,
                InstallCommand::class,
                DoctorCommand::class,
            ]);
        });
    }
}
