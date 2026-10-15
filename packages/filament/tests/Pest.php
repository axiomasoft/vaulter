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
 * Filament 5 keeps sent notifications in a cache-backed mailbox, not in the session:
 * publish the pending ones and take them out, oldest first.
 *
 * @return list<array<string, mixed>>
 */
function pullSentNotifications(): array
{
    $mailbox = app(NotificationMailbox::class);
    $mailbox->flush();

    return array_values($mailbox->pull());
}
