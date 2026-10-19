<?php

declare(strict_types=1);

namespace Vaulter\Tests\Operations\Fixtures;

use Vaulter\Operations\ConcurrencyPolicy;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\OperationPolicy;
use Vaulter\Operations\OperationPolicySource;

/** The policies of the composed operations the runner contract runs as the drive (D47): a fixture source, not a weaker check. */
final class ContractOperationPolicies implements OperationPolicySource
{
    public function policies(): array
    {
        return [
            'drive.outer.write' => new OperationPolicy('drive.contract', ConcurrencyPolicy::Append, IdempotencyPolicy::Natural),
            'drive.inner.write' => new OperationPolicy('drive.contract', ConcurrencyPolicy::Append, IdempotencyPolicy::Natural),
        ];
    }
}
