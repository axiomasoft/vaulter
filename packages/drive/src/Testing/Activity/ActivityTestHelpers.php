<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing\Activity;

use Illuminate\Database\Eloquent\Collection;
use Vaulter\Attributes\Internal;
use Vaulter\Models\Activity\Activity;

/**
 * Builders for the M2c-activity feed tests (§2.9): inspect the append-only rows the
 * ActivityRecorder writes. The recorder is event-driven, so most tests act through
 * the Drive surface and then assert the ONE row that fell out (anti-NC-fan-out).
 */
#[Internal]
final class ActivityTestHelpers
{
    /**
     * Every activity row written so far, newest-first (the canonical feed order).
     *
     * @return Collection<int, Activity>
     */
    public static function rows(): Collection
    {
        return Activity::query()->newestFirst()->get();
    }

    /**
     * The single activity row for a verb, failing loudly if there is not EXACTLY one
     * (the core anti-fan-out invariant: one event ⇒ one row).
     */
    public static function only(string $verb): Activity
    {
        $rows = Activity::query()->where('verb', $verb)->get();

        $row = $rows->first();
        if ($rows->count() !== 1 || $row === null) {
            throw new \RuntimeException("Expected exactly one [{$verb}] activity row, found {$rows->count()}.");
        }

        return $row;
    }
}
