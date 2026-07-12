<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Vaulter\Tests\P22Repro\PublishTagsBase;

require __DIR__.'/PublishTagsBase.php';

uses(PublishTagsBase::class);

it('lists every publish tag/provider across all six vaulter providers (C7/C8/C9)', function () {
    $groups = ServiceProvider::publishableGroups();
    $providers = ServiceProvider::publishableProviders();

    $byGroup = [];
    foreach ($groups as $group) {
        $paths = ServiceProvider::pathsToPublish(null, $group);
        $byGroup[$group] = $paths;
    }

    dump('publishableGroups() = '.json_encode($groups, JSON_PRETTY_PRINT));
    dump('publishableProviders() = '.json_encode($providers, JSON_PRETTY_PRINT));
    dump('paths per group = '.json_encode($byGroup, JSON_PRETTY_PRINT));

    // C8: no tag collides with a Laravel-framework built-in tag.
    $frameworkTags = ['laravel-errors', 'laravel-notifications', 'laravel-pagination', 'laravel-mail'];
    $vaulterTags = array_values(array_diff($groups, $frameworkTags));

    dump('vaulter-owned tags = '.json_encode($vaulterTags, JSON_PRETTY_PRINT));

    expect(array_intersect($vaulterTags, $frameworkTags))->toBe([]);
});
