<?php

declare(strict_types=1);

namespace Vaulter\Events\Outbox\Exceptions;

use RuntimeException;
use Vaulter\Attributes\Internal;

/**
 * A relay worker or reconciler found its lease taken over: the fenced write matched no row, so everything
 * it did under that lease is rolled back or discarded. Internal to the relay; never reaches a host.
 */
#[Internal]
final class LeaseLostException extends RuntimeException {}
