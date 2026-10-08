<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Documents\Tests\Comment\CommentsTestCase;
use Vaulter\Facades\Vaulter;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;

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
    documentProfile((new Profile)->key('default')->documentTypes('markdown'));
    $drive = Vaulter::drive(DriveTestHelpers::host())->model();
    $url = 'api/vaulter/storages/'.$drive->id.'/documents';
    $this->postJson($url, ['name' => 'blocked', 'format' => 'richtext'])->assertForbidden();
    $this->postJson($url, ['name' => 'allowed', 'format' => 'markdown'])->assertCreated();
    documentProfile((new Profile)->key('default'));
    $this->postJson($url, ['name' => 'unrestricted', 'format' => 'richtext'])->assertCreated();
});

it('enforces comments policy for list create resolve and delete including the author path', function (): void {
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($drive, null, 'thread');
    AccessTestHelpers::grant($node, RoleRegistry::OWNER);
    $this->actingAs(AccessTestHelpers::user());
    $url = 'api/vaulter/nodes/'.$node->id.'/comments';
    $id = $this->postJson($url, ['body' => 'allowed'])->assertCreated()->json('data.id');
    documentProfile((new Profile)->key('default')->comments(false));
    $this->getJson($url)->assertForbidden();
    $this->postJson($url, ['body' => 'denied'])->assertForbidden();
    $this->postJson($url.'/'.$id.'/resolve')->assertForbidden();
    $this->deleteJson($url.'/'.$id)->assertForbidden();
});
