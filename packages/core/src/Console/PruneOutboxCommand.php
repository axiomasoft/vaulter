<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Events\Outbox\OutboxRelay;

/**
 * Outbox retention: deletes events older than vaulter.outbox.retention_seconds that have no open delivery
 * and that every durable consumer not detached has reconciled past (a paused consumer holds them until its hold
 * ends), the done deliveries of deleted events, and dead deliveries older than dead_retention_seconds. Scheduled
 * hourly.
 */
#[Internal]
final class PruneOutboxCommand extends Command
{
    use OpensWriteScope;

    protected $signature = 'vaulter:outbox:prune';

    protected $description = 'Delete outbox events and deliveries past their retention without losing undelivered ones.';

    public function handle(OutboxRelay $relay, MetricsRecorder $metrics): int
    {
        $pruned = $metrics->timing(name: 'vaulter_prune_duration_seconds', operation: static fn (): array => $relay->prune(), labels: ['target' => 'outbox']);
        $metrics->counter(name: 'vaulter_prune_rows_total', amount: $pruned['events'] + $pruned['done'] + $pruned['dead'], labels: ['target' => 'outbox']);

        $this->components->info(sprintf('Pruned %d outbox event(s), %d done and %d dead deliver%s.', $pruned['events'], $pruned['done'], $pruned['dead'], $pruned['dead'] === 1 ? 'y' : 'ies'));

        return self::SUCCESS;
    }
}
