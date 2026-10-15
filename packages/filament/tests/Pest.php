<?php

declare(strict_types=1);

use Filament\Notifications\NotificationMailbox;
use Vaulter\Filament\Tests\TestCase;
use Vaulter\Tests\Support\TestGroups;

uses(TestCase::class)->in('Feature');

uses(TestCase::class)->in('Configuration');

// Test groups (P1.5): database, serial, extended, stand and the engine-only engine:* — see groups.php. This package does not
// autoload the core test classes, so the helper comes through the path-repo symlink of core.
require_once dirname(__DIR__).'/vendor/axioma-studio/vaulter-core/tests/Support/TestGroups.php';

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
