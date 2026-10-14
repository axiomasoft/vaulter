<?php

declare(strict_types=1);

namespace Vaulter\Http\Problems;

/**
 * Whether an idempotent operation that failed with a problem code stores the failure as its receipt (D25):
 * a deterministic domain outcome is replayed to a retry under the same key, while an auth, visibility,
 * validation, precondition, rate or server failure is rolled back with the claim and never cached.
 */
enum ReceiptPolicy: string
{
    case Cacheable = 'cacheable';
    case Never = 'never';
}
