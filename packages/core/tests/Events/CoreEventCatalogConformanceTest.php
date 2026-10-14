<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Data\Sharing\ShareLinkUpdate;
use Vaulter\Enums\Events\EventType;
use Vaulter\Operations\AccessOperations;
use Vaulter\Operations\DriveContext;
use Vaulter\Operations\ShareOperations;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Tests\Events\EventConformance;
use Vaulter\Tests\TestCase;

/**
 * The kernel events, produced by the real Actions, carry exactly the data their
 * EventSpec declares and nothing an envelope must not hold.
 */
uses(TestCase::class, RefreshDatabase::class, EnablesWebhooksModule::class);

it('records the kernel events with the data their specs declare', function (): void {
    config()->set('vaulter.features.share_links', true);
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
        EventType::GrantCreated, EventType::GrantRevoked, EventType::InheritanceChanged,
        EventType::ShareCreated, EventType::ShareUpdated, EventType::ShareRotated, EventType::ShareRevoked,
    ]);
});
