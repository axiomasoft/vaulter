<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule;

use Vaulter\Operations\ConcurrencyPolicy;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\OperationPolicy;
use Vaulter\Operations\OperationPolicySource;

/** The policy of the module's own operation (D47): an extension declares its operations like a built-in module. */
final class AcmeOperationPolicies implements OperationPolicySource
{
    public const string RECORD = 'acme.pings.record';

    public function policies(): array
    {
        return [
            self::RECORD => new OperationPolicy('acme.ping', ConcurrencyPolicy::Append, IdempotencyPolicy::Natural),
        ];
    }
}
