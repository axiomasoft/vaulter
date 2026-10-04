<?php

declare(strict_types=1);

use Vaulter\Exceptions\Profiles\UnknownProfileException;
use Vaulter\Profiles\DefaultProfile;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Tests\Fixtures\Panels\OverrideDefaultProfile;
use Vaulter\Tests\Fixtures\Panels\ProjectProfile;
use Vaulter\Tests\Fixtures\Panels\TeamProfile;

it('строит реестр из конфига и регистрирует дефолт первым', function (): void {
    $registry = ProfileRegistry::fromConfig(
        providerClasses: [ProjectProfile::class, TeamProfile::class],
        defaultClass: DefaultProfile::class,
    );

    expect($registry->has('default'))->toBeTrue()
        ->and($registry->has('project'))->toBeTrue()
        ->and($registry->has('team'))->toBeTrue()
        ->and($registry->has('missing'))->toBeFalse()
        ->and(array_keys($registry->all()))->toBe(['default', 'project', 'team'])
        ->and($registry->default()->getId())->toBe('default');
});

it('явная панель переопределяет дефолт, зарегистрированный первым', function (): void {
    $registry = ProfileRegistry::fromConfig(
        providerClasses: [OverrideDefaultProfile::class],
        defaultClass: DefaultProfile::class,
    );

    expect($registry->all())->toHaveCount(1)
        ->and($registry->default()->getId())->toBe('default');
});

it('get бросает UnknownProfileException на неизвестную панель', function (): void {
    $registry = ProfileRegistry::fromConfig(
        providerClasses: [],
        defaultClass: DefaultProfile::class,
    );

    $registry->get('nope');
})->throws(UnknownProfileException::class, 'Unknown Vaulter storage panel [nope]');
