<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Documents\Tests\Comment\CommentsTestCase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Facades\Vaulter;

uses(CommentsTestCase::class, RefreshDatabase::class);

function documentProfile(Profile $profile): void
{
    $registry = new ProfileRegistry;
    $registry->register($profile);
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);
    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }
}

it('rejects profile document type restrictions after ACL and leaves unrestricted types available', function (): void {
    config()->set('vaulter.access.permission_driver', 'null');
    $this->actingAs(AccessTestHelpers::user());
    documentProfile((new Profile)->key('default')->documentTypes('vaulter/markdown'));
    $drive = Vaulter::drive(DriveTestHelpers::host())->model();
    $url = 'api/vaulter/v1/drives/'.$drive->id.'/documents';
    $this->postJson($url, ['name' => 'blocked', 'type' => 'vaulter/rich-text'])->assertForbidden();
    $this->postJson($url, ['name' => 'allowed', 'type' => 'vaulter/markdown'])->assertCreated();
    documentProfile((new Profile)->key('default'));
    $this->postJson($url, ['name' => 'unrestricted', 'type' => 'vaulter/rich-text'])->assertCreated();
});

it('enforces comments policy for list create resolve and delete including the author path', function (): void {
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($drive, null, 'thread');
    AccessTestHelpers::grant($node, RoleRegistry::OWNER);
    $this->actingAs(AccessTestHelpers::user());
    $url = 'api/vaulter/v1/nodes/'.$node->id.'/comments';
    $id = $this->postJson($url, ['body' => 'allowed'])->assertCreated()->json('data.id');
    documentProfile((new Profile)->key('default')->comments(false));
    $this->getJson($url)->assertForbidden();
    $this->postJson($url, ['body' => 'denied'])->assertForbidden();
    $this->postJson($url.'/'.$id.'/resolve')->assertForbidden();
    $this->deleteJson($url.'/'.$id)->assertForbidden();
});
