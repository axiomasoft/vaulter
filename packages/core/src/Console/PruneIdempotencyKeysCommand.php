<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Vaulter\Idempotency\IdempotencyStore;

/**
 * Delete expired idempotency receipts (D25, `vaulter.idempotency.ttl_seconds`, 24 hours by default), in batches of
 * {@see self::BATCH}. Only completed receipts are deleted: a claim is never committed, so there is none to prune.
 * An expired receipt is already ignored by a retry; pruning only reclaims space. Scheduled daily.
 */
final class PruneIdempotencyKeysCommand extends Command
{
    public const int BATCH = 1000;

    protected $signature = 'vaulter:idempotency:prune
        {--limit=0 : Stop after deleting this many receipts (0 = all expired)}';

    protected $description = 'Delete expired idempotency receipts.';

    public function handle(IdempotencyStore $store): int
    {
        $limit = $this->option('limit');
        if (!is_string($limit) || preg_match('/^\d+$/', $limit) !== 1) {
            $this->components->error('--limit must be a non-negative integer.');

            return self::INVALID;
        }
        $limit = (int) $limit;

        $deleted = 0;
        do {
            $batch = $limit === 0 ? self::BATCH : min(self::BATCH, $limit - $deleted);
            $count = $batch > 0 ? $store->prune($batch) : 0;
            $deleted += $count;
        } while ($count === $batch && $batch > 0);

        $this->components->info(sprintf('Deleted %d expired idempotency receipt(s).', $deleted));

        return self::SUCCESS;
    }
}
