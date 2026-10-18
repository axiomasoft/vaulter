<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Vaulter\Attributes\Internal;
use Vaulter\Operations\OperationsReference;

/**
 * Prints the operation catalogue (D47) of every offered module: the concurrency and idempotency policy of each
 * mutating operation and the routes that run it. A Markdown table, or one JSON document with --json.
 */
#[Internal]
final class OperationsReferenceCommand extends Command
{
    protected $signature = 'vaulter:operations
        {--json : Print one JSON document}';

    protected $description = 'List the mutating operations with their concurrency and idempotency policies.';

    public function handle(OperationsReference $reference): int
    {
        if ($this->option('json') === true) {
            $this->output->writeln(json_encode(['operations' => $reference->rows()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $this->output->writeln($reference->markdown(), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
