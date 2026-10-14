<?php

declare(strict_types=1);

namespace Vaulter\Providers;

use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Foundation\Application;
use Vaulter\Console\MakeFileHandlerCommand;
use Vaulter\Console\MakePermissionDriverCommand;
use Vaulter\Console\MakeProfileCommand;
use Vaulter\Console\PruneChangesCommand;
use Vaulter\Console\ResetChangesCommand;

/** The drive commands still located in core; the core and webhooks commands are declared by their manifests. */
final class ConsoleRegistrar
{
    // P3.3 moves to DriveModule (D39).
    public function register(Application $app): void
    {
        Artisan::starting(static function (Artisan $artisan): void {
            $artisan->resolveCommands([
                MakeProfileCommand::class,
                MakeFileHandlerCommand::class,
                MakePermissionDriverCommand::class,
                PruneChangesCommand::class,
                ResetChangesCommand::class,
            ]);
        });
    }
}
