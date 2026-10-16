<?php

declare(strict_types=1);

namespace Vaulter\Documents\Profiles;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Profiles\ProfileOption;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Profiles\Profile;

/**
 * The `comments` profile option: comments are globally on while `vaulter-documents.comments.enabled` is; a
 * profile may only narrow it, which the drive's profile policy enforces.
 */
#[Internal]
final readonly class CommentsProfileOption implements ProfileOption
{
    public const string NAME = 'comments';

    public function __construct(private DocumentsConfig $config) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function enabled(): bool
    {
        return $this->config->commentsEnabled();
    }

    public function validate(Profile $profile): void {}
}
