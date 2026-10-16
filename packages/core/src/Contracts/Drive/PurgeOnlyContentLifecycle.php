<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Vaulter\Attributes\Internal;

/**
 * Marks a {@see NodeContentLifecycle} that can only purge: its copy() is a refusal,
 * not an implementation. The registry reads the marker to reject a copy of that kind
 * before anything is inserted, so a kind copies only when something richer than the
 * purge-only stand-in is registered for it.
 */
#[Internal]
interface PurgeOnlyContentLifecycle {}
