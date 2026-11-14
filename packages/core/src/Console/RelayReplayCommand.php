<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Events\Outbox\OutboxRelay;

/**
 * Closes a gap of an outbox consumer (D29): makes the events of [--from, --to] that retention still holds pending
 * again for the consumer (done and dead rows included, with a fresh attempt budget) and acknowledges the gaps the
 * range covers. A leased row is left to its worker and the gap stays open while one remains. --ack-only
 * acknowledges the loss without a replay. Exits 1 when part of the range is no longer in the outbox, and prints
 * the range it covered.
 */
#[Internal]
final class RelayReplayCommand extends Command
{
    use OpensWriteScope;

    protected $signature = 'vaulter:relay:replay
        {--consumer= : The outbox consumer id (webhooks.fanout, …)}
        {--from= : The first outbox seq}
        {--to= : The last outbox seq}
        {--ack-only : Acknowledge the gaps of the range without replaying it}';

    protected $description = 'Replay a range of the outbox to one consumer, or acknowledge its gap (D29).';

    public function handle(OutboxRelay $relay): int
    {
        $consumer = $this->option('consumer');
        $from = is_scalar($this->option('from')) ? (string) $this->option('from') : null;
        $to = is_scalar($this->option('to')) ? (string) $this->option('to') : null;
        if (!is_string($consumer) || $consumer === '' || !is_string($from) || !is_string($to)
            || preg_match('/^\d+$/', $from) !== 1 || preg_match('/^\d+$/', $to) !== 1 || (int) $from > (int) $to) {
            $this->components->error('--consumer, --from and --to are required; --from and --to are outbox seqs with --from <= --to.');

            return self::INVALID;
        }

        try {
            $result = $relay->replay($consumer, (int) $from, (int) $to, (bool) $this->option('ack-only'));
        } catch (LogicException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('ack-only')) {
            $this->components->info(sprintf('Acknowledged %d gap(s) of %s in [%s, %s].', $result['acknowledged'], $consumer, $from, $to));

            return self::SUCCESS;
        }

        $covered = $result['covered'];
        if ($covered === null) {
            $this->components->error(sprintf('No event of [%s, %s] is left in the outbox: nothing to replay; use --ack-only to acknowledge the loss.', $from, $to));

            return self::FAILURE;
        }
        $this->components->info(sprintf('Replayed %d deliver%s of %s for seqs [%d, %d] (%d dead returned to pending); acknowledged %d gap(s).', $result['rows'], $result['rows'] === 1 ? 'y' : 'ies', $consumer, $covered[0], $covered[1], $result['dead'], $result['acknowledged']));
        if ($covered[0] > (int) $from) {
            $this->components->error(sprintf('Seqs [%s, %d] are no longer in the outbox (retention): they cannot be replayed.', $from, $covered[0] - 1));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
