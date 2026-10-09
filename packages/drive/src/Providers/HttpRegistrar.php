<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

use Spatie\LaravelPackageTools\Package;
use Vaulter\Drive\Console\PruneRevisionsCommand;
use Vaulter\Drive\Console\PurgeDrivesCommand;
use Vaulter\Drive\Console\PurgeTrashCommand;
use Vaulter\Drive\Console\RecountDrivesCommand;
use Vaulter\Drive\Console\SweepUploadsCommand;

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
}
