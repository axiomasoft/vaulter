<?php

declare(strict_types=1);

namespace Vaulter\Content;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\Enums\Cas\ScanMode;

#[Api]
final readonly class ScanPolicySpec
{
    public function __construct(
        public string $predicateKey,
        public ScanMode $mode,
        public string $scannerKey,
        public string $scannerVersion,
        public string $policyVersion,
        public ?string $rolloutKey = null,
    ) {
        if (preg_match('/^(global|profile:[a-z0-9][a-z0-9-]{0,63})$/D', $predicateKey) !== 1) {
            throw new InvalidConfigurationException('Invalid scan predicate key.');
        }
        foreach ([$scannerKey, $scannerVersion, $policyVersion, ...($rolloutKey === null ? [] : [$rolloutKey])] as $id) {
            if (preg_match('/^[a-zA-Z0-9._:-]{1,64}$/D', $id) !== 1) {
                throw new InvalidConfigurationException('Invalid scan policy identifier.');
            }
        }
    }

    /** Eligibility facts are supplied by persisted activation, never inferred here. */
    public function requiresScan(int $admissionSequence, ?int $boundarySequence): bool
    {
        if ($admissionSequence < 0 || ($boundarySequence !== null && $boundarySequence < 0)) {
            throw new InvalidConfigurationException('Scan sequences must be nonnegative.');
        }

        return match ($this->mode) {
            ScanMode::Off => false,
            ScanMode::AllContent => true,
            ScanMode::NewContentOnly => $boundarySequence === null
                ? throw new InvalidConfigurationException('Persisted scan activation boundary is missing.')
                : $admissionSequence > $boundarySequence,
        };
    }

    public function configFingerprint(): string
    {
        return hash('sha256', json_encode(['mode' => $this->mode->value, 'policy_version' => $this->policyVersion, 'predicate_key' => $this->predicateKey, 'rollout_key' => $this->rolloutKey, 'scanner_key' => $this->scannerKey, 'scanner_version' => $this->scannerVersion], JSON_THROW_ON_ERROR));
    }

    public function specKey(): string
    {
        return hash('sha256', json_encode(['policy_version' => $this->policyVersion, 'scanner_key' => $this->scannerKey, 'scanner_version' => $this->scannerVersion], JSON_THROW_ON_ERROR));
    }
}
