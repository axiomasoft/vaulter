<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Configuration;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\VaulterException;

/** Configuration failures are raised during boot and have no HTTP rendering. */
#[Api]
final class InvalidConfigurationException extends VaulterException
{
    public function problemCode(): string
    {
        return 'invalid_configuration';
    }
}
