<?php

declare(strict_types=1);

namespace Vaulter\Documents\Profiles;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Profiles\ProfileOption;
use Vaulter\Profiles\Profile;

/**
 * The `allowed_document_types` profile option: a profile may restrict its drives to some document types
 * ({@see Profile::documentTypes()}); the drive's profile policy enforces the list when a document is created.
 * The type registry is filled while the documents module boots, after the profiles are validated, so the keys are
 * not checked against it here.
 */
#[Internal]
final readonly class AllowedDocumentTypesProfileOption implements ProfileOption
{
    public const string NAME = 'allowed_document_types';

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
