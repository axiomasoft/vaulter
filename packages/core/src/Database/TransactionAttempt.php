<?php

declare(strict_types=1);

namespace Vaulter\Database;

use Packman\Attributes\Internal;

/** Shared by the outer callback and any work it schedules for after commit. */
#[Internal]
final class TransactionAttempt
{
    public bool $aborted = false;

    public bool $callbackReturned = false;
}
