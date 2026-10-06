<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Vaulter\Drive\Http\Resources\DriveResource;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\Access\ProfileCreateGate;

it('projects create flags from the profile-selected create driver', function (bool $allow): void {
    $drive = AccessTestHelpers::storage();
    $request = Request::create('/');
    $request->setUserResolver(static fn () => AccessTestHelpers::user());
    ProfileCreateGate::install($allow);
    $data = (new DriveResource($drive))->resolve($request);
    expect($data['abilities']['create_folder'])->toBe($allow)
        ->and($data['abilities']['upload'])->toBe($allow)
        ->and($data['abilities']['view'])->toBeTrue();
})->with([true, false]);
