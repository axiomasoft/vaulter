<?php

declare(strict_types=1);

use Vaulter\Profiles\Profile;
use Vaulter\Tests\Fixtures\Panels\FakeProject;

it('применяет дефолты фич Vaulter с override панели', function (): void {
    $panel = (new Profile)->id('p');

    expect($panel->featureEnabled('grants'))->toBeTrue()
        ->and($panel->featureEnabled('versions'))->toBeTrue()
        ->and($panel->featureEnabled('blobs'))->toBeTrue()
        ->and($panel->featureEnabled('activity'))->toBeTrue()
        ->and($panel->featureEnabled('uploads'))->toBeTrue()
        ->and($panel->featureEnabled('extra_data'))->toBeTrue()
        ->and($panel->featureEnabled('reliability'))->toBeTrue()
        ->and($panel->featureEnabled('share_links'))->toBeFalse()
        ->and($panel->featureEnabled('public_listing'))->toBeFalse()
        ->and($panel->featureEnabled('document_versions'))->toBeFalse();

    // grants follows hasGrants; explicit override wins.
    expect((new Profile)->withoutGrants()->featureEnabled('grants'))->toBeFalse()
        ->and((new Profile)->withoutGrants()->feature('grants', true)->featureEnabled('grants'))->toBeTrue()
        ->and((new Profile)->feature('share_links', true)->featureEnabled('share_links'))->toBeTrue();
});

it('хранит host, storage type и grants', function (): void {
    $panel = (new Profile)->id('p')->host(FakeProject::class)->storageType('team');

    expect($panel->getId())->toBe('p')
        ->and($panel->hostModel())->toBe(FakeProject::class)
        ->and($panel->storageTypeKey())->toBe('team')
        ->and($panel->hasGrants())->toBeTrue()
        ->and((new Profile)->withoutGrants()->hasGrants())->toBeFalse();
});
