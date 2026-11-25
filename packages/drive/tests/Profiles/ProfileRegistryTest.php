<?php

declare(strict_types=1);

use Vaulter\Drive\Exceptions\Profiles\InvalidProfileException;
use Vaulter\Drive\Exceptions\Profiles\UnknownProfileException;
use Vaulter\Drive\Profiles\DefaultProfile;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Testing\Fixtures\Panels\OverrideDefaultProfile;
use Vaulter\Drive\Testing\Fixtures\Panels\ProjectProfile;
use Vaulter\Drive\Testing\Fixtures\Panels\TeamProfile;

it('builds the registry from config and registers the default first', function (): void {
    $registry = ProfileRegistry::fromConfig(
        providerClasses: [ProjectProfile::class, TeamProfile::class],
        defaultClass: DefaultProfile::class,
    );

    expect($registry->has('default'))->toBeTrue()
        ->and($registry->has('project'))->toBeTrue()
        ->and($registry->has('team'))->toBeTrue()
        ->and($registry->has('missing'))->toBeFalse()
        ->and(array_keys($registry->all()))->toBe(['default', 'project', 'team'])
        ->and($registry->default()->getKey())->toBe('default');
});

it('rejects duplicate explicit default keys', function (): void {
    ProfileRegistry::fromConfig(providerClasses: [OverrideDefaultProfile::class], defaultClass: DefaultProfile::class);
})->throws(InvalidProfileException::class, 'unique');

it('get throws UnknownProfileException for an unknown panel', function (): void {
    $registry = ProfileRegistry::fromConfig(
        providerClasses: [],
        defaultClass: DefaultProfile::class,
    );

    $registry->get('nope');
})->throws(UnknownProfileException::class, 'Unknown Vaulter storage panel [nope]');
