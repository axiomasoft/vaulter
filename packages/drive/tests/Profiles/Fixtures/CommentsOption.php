<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests\Profiles\Fixtures;

use Vaulter\Drive\Contracts\Profiles\ProfileOption;
use Vaulter\Drive\Profiles\Profile;

final class CommentsOption implements ProfileOption
{
    public const string NAME = 'comments';

    public function name(): string
    {
        return self::NAME;
    }

    public function enabled(): bool
    {
        return true;
    }

    public function validate(Profile $profile): void {}
}
