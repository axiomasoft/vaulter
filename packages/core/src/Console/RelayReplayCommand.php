<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Events\Outbox\OutboxRelay;

/**
 * Closes a gap of an outbox consumer: makes the events of [--from, --to] that retention still holds pending
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

    protected $description = 'Replay a range of the outbox to one consumer, or acknowledge its gap.';

    public function handle(OutboxRelay $relay): int
    {
        $consumer = $this->option('consumer');
        $from = self::digitOption($this->option('from'));
        $to = self::digitOption($this->option('to'));
        if (!is_string($consumer) || $consumer === '' || $from === null || $to === null || $from > $to) {
            $this->components->error('--consumer, --from and --to are required; --from and --to are outbox seqs with --from <= --to.');

            return self::INVALID;
        }

        try {
            $result = $relay->replay($consumer, $from, $to, $this->option('ack-only'));
        } catch (LogicException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('ack-only') === true) {
            $this->components->info(sprintf('Acknowledged %d gap(s) of %s in [%d, %d].', $result['acknowledged'], $consumer, $from, $to));

            return self::SUCCESS;
        }

        $covered = $result['covered'];
        if ($covered === null) {
            $this->components->error(sprintf('No event of [%d, %d] is left in the outbox: nothing to replay; use --ack-only to acknowledge the loss.', $from, $to));

            return self::FAILURE;
        }
        $this->components->info(sprintf('Replayed %d deliver%s of %s for seqs [%d, %d] (%d dead returned to pending); acknowledged %d gap(s).', $result['rows'], $result['rows'] === 1 ? 'y' : 'ies', $consumer, $covered[0], $covered[1], $result['dead'], $result['acknowledged']));
        if ($covered[0] > $from) {
            $this->components->error(sprintf('Seqs [%d, %d] are no longer in the outbox (retention): they cannot be replayed.', $from, $covered[0] - 1));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private static function digitOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
