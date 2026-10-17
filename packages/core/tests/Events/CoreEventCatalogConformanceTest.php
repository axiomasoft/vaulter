<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Data\Sharing\ShareLinkUpdate;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Operations\AccessOperations;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\ShareOperations;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Testing\Events\EventConformance;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Tests\TestCase;

/**
 * The kernel events, produced by the real Actions, carry exactly the data their
 * EventSpec declares and nothing an envelope must not hold.
 */
uses(TestCase::class, RefreshDatabase::class, EnablesWebhooksModule::class);

it('records the kernel events with the data their specs declare', function (): void {
    config()->set('vaulter-drive.features.share_links', true);
    $drive = AccessTestHelpers::storage();
    $context = DriveContext::system($drive, 'conformance');
    $access = app()->makeWith(AccessOperations::class, ['context' => $context]);
    $shares = app()->makeWith(ShareOperations::class, ['context' => $context]);
    $folder = AccessTestHelpers::folder($drive, null, 'secret-name');

    $access->grant($folder->id, new GrantInput(subject: new Subject('user', 14), role: RoleRegistry::READER));
    $access->revoke($folder->id, $access->grants($folder->id)[0]);
    $access->restrictInheritance($folder->id);
    $access->restoreInheritance($folder->id);
    $minted = $shares->create($folder->id, new ShareLinkInput);
    $shares->update($folder->id, $minted->link->id, new ShareLinkUpdate(maxUses: 4, password: 'open-sesame'));
    $shares->rotate($folder->id, $minted->link->id);
    $shares->revoke($folder->id, $minted->link->id);

    EventConformance::assertRecorded([
        DriveEventType::GrantCreated, DriveEventType::GrantRevoked, DriveEventType::InheritanceChanged,
        DriveEventType::ShareCreated, DriveEventType::ShareUpdated, DriveEventType::ShareRotated, DriveEventType::ShareRevoked,
    ]);
});
