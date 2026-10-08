<?php

declare(strict_types=1);

namespace Vaulter\Console\Concerns;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vaulter\Database\VaulterDatabase;

/**
 * Runs a Vaulter command as a Vaulter write: the models it changes pass the write guard
 * (PLAN2.D44). A command is maintenance work done by the package, not by a caller.
 *
 * @internal
 */
trait OpensWriteScope
{
    public function run(InputInterface $input, OutputInterface $output): int
    {
        return $this->laravel->make(VaulterDatabase::class)->write(fn (): int => parent::run($input, $output));
    }
}
