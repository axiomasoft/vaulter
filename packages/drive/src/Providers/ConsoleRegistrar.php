<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

use Illuminate\Console\Application;
use Illuminate\Contracts\Container\Container;
use Vaulter\Drive\Console\ReconcileScanWorkCommand;
use Vaulter\Drive\Console\RescanFileRevisionsCommand;

final class ConsoleRegistrar
{
    public function boot(Container $app): void
    {
        Application::starting(function ($artisan): void {
            $artisan->resolveCommands([ReconcileScanWorkCommand::class, RescanFileRevisionsCommand::class]);
        });
    }
}
