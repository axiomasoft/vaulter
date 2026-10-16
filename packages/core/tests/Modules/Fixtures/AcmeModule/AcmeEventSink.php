<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures\AcmeModule;

use RuntimeException;
use Vaulter\Events\DomainEvent;
use Vaulter\Events\EventSink;
use Vaulter\Events\SinkResult;
use Vaulter\Events\SinkWriter;

/** Observes the events of an operation inside its transaction and keeps none of them (an observer changes no result). */
final class AcmeEventSink implements EventSink
{
    /** observe | throw */
    public static string $mode = 'observe';

    /** @var list<array{event: string, journalSeq: int|string|null, transactionLevel: int}> */
    public static array $seen = [];

    public function __construct()
    {
        AcmeProbe::resolved(self::class);
    }

    public function record(DomainEvent $event, SinkWriter $writer): SinkResult
    {
        self::$seen[] = [
            'event' => $event->eventId,
            'journalSeq' => $writer->shared(SinkWriter::JOURNAL_SEQ),
            'transactionLevel' => $writer->connection()->transactionLevel(),
        ];

        return self::$mode === 'throw' ? throw new RuntimeException('acme sink failed') : SinkResult::Skipped;
    }
}
