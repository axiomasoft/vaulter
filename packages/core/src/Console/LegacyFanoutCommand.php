<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Repositories\Webhooks\OutboxStoreRepository;
use Vaulter\Support\Identity;

/**
 * Lists — and on request replays — outbox rows the pre-1.0 relay settled without any
 * delivery. That relay marked a row before writing its deliveries, so a row with
 * none is either an event no endpoint subscribed to or a fan-out lost to a crash;
 * the stored data cannot tell which.
 *
 * Without --replay it only prints the rows (event_id, event, created_at, drive_id)
 * and their count. --replay returns them to the relay queue, which fans them out to
 * the endpoints eligible now: an endpoint inactive when the event happened may receive
 * it, an endpoint that already has the delivery does not get a second one. Rows the
 * retention prune already deleted are gone and are not reconstructed. --dry-run with
 * --replay reports what would be replayed and changes nothing.
 */
final class LegacyFanoutCommand extends Command
{
    use OpensWriteScope;

    private const int CHUNK = 500;

    protected $signature = 'vaulter:webhooks:legacy-fanout
        {--since= : Only rows created at or after this date/time}
        {--until= : Only rows created at or before this date/time}
        {--replay : Return the listed rows to the relay queue}
        {--dry-run : With --replay, report what would be replayed without changing anything}';

    protected $description = 'List or replay legacy outbox rows whose webhook fan-out cannot be proven.';

    public function handle(OutboxStoreRepository $outbox, VaulterConfig $config): int
    {
        if (!$config->featureEnabled('webhooks')) {
            $this->components->info('Webhooks feature is off — nothing to inspect.');

            return self::SUCCESS;
        }

        try {
            $since = $this->dateOption('since');
            $until = $this->dateOption('until');
        } catch (Throwable) {
            $this->components->error('--since and --until must be dates or date-times.');

            return self::FAILURE;
        }

        $replay = (bool) $this->option('replay') && !(bool) $this->option('dry-run');
        $rows = [];
        $replayed = 0;
        // Key order: chunkById pages by key, and replayed rows leave the result set.
        $outbox->ambiguousLegacy(since: $since, until: $until)->reorder()->chunkById(self::CHUNK, function ($chunk) use ($outbox, $replay, &$rows, &$replayed): void {
            /** @var iterable<Outbox> $chunk */
            $ids = [];
            foreach ($chunk as $message) {
                $rows[] = [$message->event_id, $message->event, $message->created_at?->toDateTimeString() ?? '', Identity::stringify($message->drive_id)];
                $key = Identity::key($message->getKey());
                if ($key !== null) {
                    $ids[] = $key;
                }
            }

            if ($replay) {
                $replayed += $outbox->replayLegacy(ids: $ids);
            }
        });

        if ($rows !== []) {
            $this->table(['event_id', 'event', 'created_at', 'drive_id'], $rows);
        }

        $this->components->info(match (true) {
            $replay => sprintf('Replayed %d of %d legacy outbox row%s; the relay fans them out on its next run.', $replayed, count($rows), count($rows) === 1 ? '' : 's'),
            (bool) $this->option('replay') => sprintf('Would replay %d legacy outbox row%s.', count($rows), count($rows) === 1 ? '' : 's'),
            default => sprintf('%d legacy outbox row%s without a provable fan-out.', count($rows), count($rows) === 1 ? '' : 's'),
        });

        return self::SUCCESS;
    }

    private function dateOption(string $name): ?Carbon
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
