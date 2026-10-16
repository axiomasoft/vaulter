<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures\AcmeModule;

use Illuminate\Console\Command;

final class AcmeCommand extends Command
{
    protected $signature = 'acme:ping';

    protected $description = 'Prints the greeting of the AcmeModule';

    public function __construct()
    {
        parent::__construct();
        AcmeProbe::resolved(self::class);
    }

    public function handle(AcmeService $service): int
    {
        $this->line($service->greeting());

        return self::SUCCESS;
    }
}
