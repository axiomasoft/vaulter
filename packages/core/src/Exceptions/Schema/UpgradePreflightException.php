<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Schema;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

final class UpgradePreflightException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    /** @param list<string> $problems */
    public function __construct(array $problems)
    {
        parent::__construct('[Vaulter] Upgrade preflight failed: '.implode('; ', $problems).'. Run php artisan vaulter:upgrade:inspect before retrying.');
    }

    public function problemCode(): string
    {
        return 'upgrade_preflight_failed';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
