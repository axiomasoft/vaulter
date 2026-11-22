<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use Vaulter\Idempotency\IdempotencyStore;

/**
 * Compact expired idempotency receipts and remove tombstones after their seven-day retention.
 * Scheduled daily in batches of {@see self::BATCH}.
 */
#[Internal]
final class PruneIdempotencyKeysCommand extends Command
{
    public const int BATCH = 1000;

    protected $signature = 'vaulter:idempotency:prune
        {--limit=0 : Stop after processing this many receipts (0 = all expired)}';

    protected $description = 'Compact expired idempotency receipts and remove elapsed tombstones.';

    public function handle(IdempotencyStore $store): int
    {
        $limit = $this->option('limit');
        if (!is_string($limit) || preg_match('/^\d+$/', $limit) !== 1) {
            $this->components->error('--limit must be a non-negative integer.');

            return self::INVALID;
        }
        $limit = (int) $limit;

        $processed = 0;
        do {
            $batch = $limit === 0 ? self::BATCH : min(self::BATCH, $limit - $processed);
            $count = $batch > 0 ? $store->prune($batch) : 0;
            $processed += $count;
        } while ($count === $batch && $batch > 0);

        $this->components->info(sprintf('Processed %d expired idempotency receipt(s).', $processed));

        return self::SUCCESS;
    }
}
