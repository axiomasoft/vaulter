<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Events\Fixtures;

use RuntimeException;
use Vaulter\Events\DomainEvent;
use Vaulter\Events\EventSink;
use Vaulter\Events\SinkResult;
use Vaulter\Events\SinkWriter;

/**
 * An event sink that answers what the test scripted for its class and logs the calls, so the contract test
 * of the EventSink slot sees the order and the replay semantics of the recorder.
 */
abstract class ScriptedSink implements EventSink
{
    /** @var list<string> */
    public static array $log = [];

    /** @var array<class-string, SinkResult|'throw'|'connection'> */
    public static array $script = [];

    /** @var array<string, int|string|null> what the last sink saw shared before it ran */
    public static array $seen = [];

    public static function reset(): void
    {
        self::$log = [];
        self::$script = [];
        self::$seen = [];
    }

    public function record(DomainEvent $event, SinkWriter $writer): SinkResult
    {
        self::$log[] = static::class;
        self::$seen[static::class] = $writer->shared('probe');
        $writer->share('probe', static::class);
        $answer = self::$script[static::class] ?? SinkResult::Stored;

        if ($answer === 'connection') {
            $writer->connection();

            return SinkResult::Stored;
        }

        return $answer === 'throw' ? throw new RuntimeException('sink failed: '.static::class) : $answer;
    }
}
