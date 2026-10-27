<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\State;

use Closure;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\State\PendingMarker;
use Vaulter\Documents\State\PendingStateDrainer;

/**
 * A transport stand-in: records every drain request with the transaction level it came at, and does what the test
 * sets in $behaviour (publish a covering checkpoint, or nothing — a deadline that passed).
 */
final class FakePendingStateDrainer implements PendingStateDrainer
{
    public function protocol(): string
    {
        return 'yjs';
    }

    /** @var list<array{marker: PendingMarker, transactionLevel: int}> */
    public static array $calls = [];

    /** @var (Closure(PendingMarker): void)|null */
    public static ?Closure $behaviour = null;

    public function __construct(private readonly VaulterDatabase $database) {}

    public static function reset(): void
    {
        self::$calls = [];
        self::$behaviour = null;
    }

    public function drain(PendingMarker $marker): void
    {
        self::$calls[] = ['marker' => $marker, 'transactionLevel' => $this->database->connection()->transactionLevel()];
        if (self::$behaviour !== null) {
            (self::$behaviour)($marker);
        }
    }
}
