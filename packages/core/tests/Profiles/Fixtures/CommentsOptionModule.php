<?php

declare(strict_types=1);

namespace Vaulter\Tests\Profiles\Fixtures;

use Vaulter\Contracts\Profiles\ProfileOption;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\VaulterModule;

/** A module that owns the comments feature, as the documents module does: it contributes the `comments` option. */
final class CommentsOptionModule implements VaulterModule
{
    public function id(): ModuleId
    {
        return new ModuleId('comments-owner');
    }

    public function version(): string
    {
        return '1.0';
    }

    public function activation(): Activation
    {
        return Activation::Auto;
    }

    public function requires(): array
    {
        return ['core' => '^1.0', 'drive' => '^1.0'];
    }

    public function register(ModuleBuilder $module): void
    {
        $module->slot(ProfileOption::class, CommentsOption::class, 'fixture.profile.comments', key: CommentsOption::NAME);
    }
}
