<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Vaulter\Attributes\Internal;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Events\Outbox\OutboxRelay;

/**
 * The outbox relay (D29): registers the outbox consumers of the module graph, reconciles the delivery rows they
 * missed, delivers the due ones and detaches consumers paused past their hold. Without --once it repeats passes
 * while work remains, for at most --max-seconds. Idempotent and fenced: overlapping runs never deliver one row
 * twice under the same lease; the schedule runs it every minute without overlapping.
 */
#[Internal]
final class RelayCommand extends Command
{
    use OpensWriteScope;

    protected $signature = 'vaulter:relay
        {--consumer= : Only this outbox consumer (registration and holds still cover all)}
        {--limit= : Deliveries leased per consumer and pass (default vaulter.outbox.batch)}
        {--once : Run a single pass}
        {--max-seconds=50 : Stop starting passes after this many seconds}';

    protected $description = 'Relay the outbox to its consumers: register, reconcile, deliver, hold (D29).';

    public function handle(OutboxRelay $relay): int
    {
        $limit = $this->positive('limit');
        $maxSeconds = $this->positive('max-seconds');
        if ($limit === false || $maxSeconds === false) {
            $this->components->error('--limit and --max-seconds must be positive integers.');

            return self::INVALID;
        }
        $consumer = $this->option('consumer');
        $consumer = is_string($consumer) && $consumer !== '' ? $consumer : null;
        if ($consumer !== null && !array_key_exists($consumer, $relay->consumers())) {
            $this->components->error(sprintf('The outbox consumer "%s" is not in the active module graph.', $consumer));

            return self::FAILURE;
        }

        $started = microtime(true);
        $total = 0;
        do {
            $finished = $relay->pass($consumer, $limit);
            $total += array_sum($finished);
            $more = array_sum($finished) > 0 && !$this->option('once');
        } while ($more && microtime(true) - $started < ($maxSeconds ?? 50));

        $this->components->info(sprintf('Relayed %d outbox deliver%s.', $total, $total === 1 ? 'y' : 'ies'));

        return self::SUCCESS;
    }

    private function positive(string $option): int|false|null
    {
        $value = $this->option($option);
        if ($value === null || $value === '') {
            return null;
        }
        $value = is_scalar($value) ? (string) $value : '';

        return preg_match('/^[1-9]\d*$/', $value) === 1 ? (int) $value : false;
    }
}
