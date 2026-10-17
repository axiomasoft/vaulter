<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Configuration\DriveConfigValidator;
use Vaulter\Drive\Contracts\Profiles\ProfileOption;
use Vaulter\Drive\Exceptions\Profiles\InvalidProfileException;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Drive\Profiles\ProfileProvider;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Profiles\UploadPolicy;
use Vaulter\Drive\Tests\Profiles\Fixtures\CommentsOptionProvider;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Kernel;
use Vaulter\Testing\Concerns\FreshApplication;
use Vaulter\Testing\Fixtures\Panels\FakeProject;
use Vaulter\Testing\Fixtures\TestUser;

it('treats comments as globally off when no module contributes the option and leaves the other flags untouched', function (): void {
    $profile = (new Profile)->key('p');
    $policy = app(ProfilePolicy::class);

    expect(app(Kernel::class)->extensions()->handlerKeys(ProfileOption::class))->not->toContain('comments')
        ->and($policy->featureEnabled($profile, 'comments'))->toBeFalse()
        ->and($policy->featureEnabled($profile, 'grants'))->toBeTrue();
});

it('inherits global flags and cannot enable a globally disabled feature', function (): void {
    $profile = (new Profile)->key('p');
    $policy = app(ProfilePolicy::class);
    $policy->validate($profile);
    expect($policy->featureEnabled($profile, 'grants'))->toBeTrue()
        ->and($policy->featureEnabled($profile, 'share_links'))->toBeFalse();
    $policy->validate($profile->shareLinks(true));
})->throws(InvalidConfigurationException::class, 'globally disabled');

it('conjoins every explicit profile flag with the global policy', function (string $feature): void {
    config()->set('vaulter-drive.features.'.$feature, true);
    $setter = ['grants' => 'grants', 'share_links' => 'shareLinks'][$feature];
    $profile = (new Profile)->key('p');
    expect(app(ProfilePolicy::class)->featureEnabled($profile, $feature))->toBeTrue();
    $profile->$setter(false);
    expect(app(ProfilePolicy::class)->featureEnabled($profile, $feature))->toBeFalse();
})->with(['grants', 'share_links']);

it('conjoins the comments flag with the option of the module that owns comments', function (): void {
    // Core does not read the documents package's config: the contributing module answers.
    $policy = FreshApplication::boot(after: [CommentsOptionProvider::class])->make(ProfilePolicy::class);
    $profile = (new Profile)->key('p');

    expect($policy->featureEnabled($profile, 'comments'))->toBeTrue();
    $profile->comments(false);
    expect($policy->featureEnabled($profile, 'comments'))->toBeFalse();
});

it('rejects malformed profile values', function (Closure $build): void {
    $build();
})->with([
    'key underscore' => fn () => (new Profile)->key('project_drive'),
    'blank key' => fn () => (new Profile)->key(''),
    'long key' => fn () => (new Profile)->key(str_repeat('a', 65)),
    'owner class' => fn () => (new Profile)->owners(stdClass::class),
    'negative quota' => fn () => (new Profile)->defaultQuota(-1),
    'zero revisions' => fn () => (new Profile)->revisionLimit(0),
    'negative retention' => fn () => (new Profile)->trashRetentionDays(-1),
    'blank disk' => fn () => (new Profile)->disk(''),
    'negative upload size' => fn () => new UploadPolicy(maxFileSize: -1),
])->throws(InvalidProfileException::class);

it('checks owner subtypes and leaves empty owner restrictions open', function (): void {
    $profile = (new Profile)->key('p')->owners(FakeProject::class);
    expect(app(ProfilePolicy::class)->ownerAllowed($profile, new FakeProject))->toBeTrue()
        ->and(app(ProfilePolicy::class)->ownerAllowed((new Profile)->key('base')->owners(Model::class), new FakeProject))->toBeTrue()
        ->and(app(ProfilePolicy::class)->ownerAllowed($profile, new TestUser))->toBeFalse()
        ->and(app(ProfilePolicy::class)->ownerAllowed((new Profile)->key('any'), new FakeProject))->toBeTrue();
});

it('rejects unknown disks and permission driver keys at validation', function (Profile $profile): void {
    app(ProfilePolicy::class)->validate($profile);
})->with([
    'disk' => fn () => (new Profile)->key('p')->disk('missing'),
    'driver' => fn () => (new Profile)->key('p')->permissionDriver('missing'),
])->throws(InvalidConfigurationException::class);

it('hydrates DI profile providers only once during boot validation', function (): void {
    $count = 0;
    $provider = new class extends ProfileProvider
    {
        public function profile(Profile $profile): Profile
        {
            return $profile->key('custom');
        }
    };
    app()->bind($provider::class, function () use (&$count, $provider) {
        $count++;

        return $provider;
    });
    config()->set('vaulter-drive.profiles.providers', [$provider::class]);
    app()->forgetInstance(ProfileRegistry::class);
    (new DriveConfigValidator(app('config')))->validate(app(DriveConfig::class), app());
    app(ProfileRegistry::class);
    (new DriveConfigValidator(app('config')))->validate(app(DriveConfig::class), app());
    expect($count)->toBe(1);
});
