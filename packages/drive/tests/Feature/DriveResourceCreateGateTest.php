<?php

declare(strict_types=1);

use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\Access\ProfileCreateGate;

/**
 * The drive resource no longer projects create flags; the profile-selected create gate
 * is enforced where it matters, on the create routes themselves.
 */
it('enforces the profile-selected create gate on folder creation over v1', function (bool $allow): void {
    $drive = AccessTestHelpers::storage();
    $this->actingAs(AccessTestHelpers::user());
    ProfileCreateGate::install($allow);

    $this->postJson('api/vaulter/v1/drives/'.$drive->getKey().'/folders', ['name' => 'Gated'])
        ->assertStatus($allow ? 201 : 403);
    $this->getJson('api/vaulter/v1/drives/'.$drive->getKey())->assertOk()->assertJsonMissingPath('data.abilities');
})->with([true, false]);
