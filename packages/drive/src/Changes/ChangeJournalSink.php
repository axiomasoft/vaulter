<?php

declare(strict_types=1);

namespace Vaulter\Drive\Changes;

use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Events\DriveEvent;
use Vaulter\Events\DomainEvent;
use Vaulter\Events\EventSink;
use Vaulter\Events\SinkResult;
use Vaulter\Events\SinkWriter;

/**
 * The drive's change journal as an event sink (`drive.changes`): the entry of a journalled occurrence
 * while `vaulter-drive.features.changes` is on. Its seq is shared with the writer under
 * {@see SinkWriter::JOURNAL_SEQ}, so the outbox envelope written after the sinks carries it. The journal takes
 * the drive's head lock, so the sinks after it must not lock. */
#[Internal]
final readonly class ChangeJournalSink implements EventSink
{
    public function __construct(
        private DriveOptions $config,
        private Container $container,
    ) {}

    public function record(DomainEvent $event, SinkWriter $writer): SinkResult
    {
        $journal = $event instanceof DriveEvent && $this->config->featureEnabled('changes') ? $event->journal() : null;
        if ($journal === null) {
            return SinkResult::Skipped;
        }

        $seq = $this->container->make(ChangeJournal::class)->append($event, $journal);
        if ($seq === null) {
            return SinkResult::Replayed;
        }
        $writer->share(SinkWriter::JOURNAL_SEQ, $seq);

        return SinkResult::Stored;
    }
}
