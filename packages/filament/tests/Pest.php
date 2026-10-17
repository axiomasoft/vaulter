<?php

declare(strict_types=1);

use Filament\Notifications\NotificationMailbox;
use Vaulter\Filament\Tests\TestCase;
use Vaulter\Testing\Support\TestGroups;

uses(TestCase::class)->in('Feature');

uses(TestCase::class)->in('Configuration');

// Test groups (P1.5): database, serial, extended, stand and the engine-only engine:* — see groups.php. The helper is part of the core Testing kit.

TestGroups::bind(__DIR__, 'filament');

/**
 * Notifications sent so far, oldest first, taken out of the store. Newer Filament 5 releases keep them in a cache-backed
 * mailbox, ^5.0 up to 5.10.0 in the session: the helper reads whichever the installed release writes to.
 *
 * @return list<array<string, mixed>>
 */
function pullSentNotifications(): array
{
    if (!class_exists(NotificationMailbox::class)) {
        return array_values(session()->pull('filament.notifications', []));
    }

    $mailbox = app(NotificationMailbox::class);
    $mailbox->flush();

    return array_values($mailbox->pull());
}
