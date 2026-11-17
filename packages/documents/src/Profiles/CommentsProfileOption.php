<?php

declare(strict_types=1);

namespace Vaulter\Documents\Profiles;

use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;
use Vaulter\Documents\Configuration\DocumentsOptions;
use Vaulter\Drive\Contracts\Profiles\ProfileOption;
use Vaulter\Drive\Profiles\Profile;

/**
 * The `comments` profile option: comments are globally on while `vaulter-documents.comments.enabled` is; a
 * profile may only narrow it, which the drive's profile policy enforces.
 */
#[Internal]
final readonly class CommentsProfileOption implements ProfileOption
{
    public const string NAME = 'comments';

    public function __construct(private Container $container) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function enabled(): bool
    {
        return $this->container->make(DocumentsOptions::class)->comments->enabled;
    }

    public function validate(Profile $profile): void {}
}
