<?php

declare(strict_types=1);

namespace Vaulter\Content;

use Vaulter\Attributes\Api;
use Vaulter\Enums\Cas\ScanVerdict;

#[Api]
final readonly class ScanResult
{
    public const array REASONS = ['scanner_timeout', 'scanner_unavailable', 'transient_io', 'unsupported', 'too_large', 'password_protected', 'access_denied', 'unknown', 'content_identity_mismatch', 'attempts_exhausted', 'superseded_policy'];

    public function __construct(public ScanVerdict $verdict, public ?string $reason = null, public bool $retryable = false)
    {
        if ($verdict !== ScanVerdict::Failed && ($reason !== null || $retryable)) {
            throw new \InvalidArgumentException('Successful verdict cannot carry a failure.');
        }
        if ($verdict === ScanVerdict::Failed && (!in_array($reason, self::REASONS, true) || ($retryable && !in_array($reason, ['scanner_timeout', 'scanner_unavailable', 'transient_io'], true)))) {
            throw new \InvalidArgumentException('Invalid structured scan failure.');
        }
    }
}
