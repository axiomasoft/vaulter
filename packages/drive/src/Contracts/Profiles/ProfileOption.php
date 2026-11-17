<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Profiles;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

/**
 * A profile option a module owns (slot `drive`, key = the option name): the documents module adds `comments`
 * and `allowed_document_types`. The drive asks the option whether its feature is globally on and lets it
 * validate a profile, so the drive never names the module that owns the feature.
 */
#[Spi(SpiKind::Implement)]
interface ProfileOption
{
    /** The option name; the slot key. */
    public function name(): string;

    /** Whether the owning module has the option globally on; a profile may only narrow it. */
    public function enabled(): bool;

    /**
     * @throws InvalidConfigurationException when the profile uses the option in a way the owner refuses
     */
    public function validate(Profile $profile): void;
}
