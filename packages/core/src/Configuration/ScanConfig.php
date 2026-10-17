<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Content\ScanPolicySpec;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\Enums\Cas\ScanMode;

#[Api]
final readonly class ScanConfig
{
    #[Internal]
    public function __construct(private Repository $config) {}

    public function mode(): ScanMode
    {
        $mode = $this->value('mode');
        $required = $this->value('required');
        if ($required !== null && !is_bool($required)) {
            throw new InvalidConfigurationException('Scan required must be bool or null.');
        }
        $resolved = $mode === null ? null : (is_string($mode) ? ScanMode::tryFrom($mode) : null);
        if ($mode !== null && $resolved === null) {
            throw new InvalidConfigurationException('Unknown scan mode.');
        }
        if ($required === true && $resolved !== null && $resolved !== ScanMode::AllContent) {
            throw new InvalidConfigurationException('Scan required=true conflicts with the explicit mode.');
        }

        return $required === true ? ScanMode::AllContent : ($resolved ?? ScanMode::Off);
    }

    public function globalSpec(): ScanPolicySpec
    {
        return new ScanPolicySpec(
            predicateKey: 'global', mode: $this->mode(),
            scannerKey: $this->stringValue('scanner_key') ?? 'none',
            scannerVersion: $this->stringValue('scanner_version') ?? 'none',
            policyVersion: $this->stringValue('policy_version') ?? '1',
            rolloutKey: $this->stringValue('rollout_key'),
        );
    }

    public function scanner(): ?string
    {
        return $this->stringValue('scanner');
    }

    private function stringValue(string $key): ?string
    {
        $value = $this->value($key);
        if ($value !== null && !is_string($value)) {
            throw new InvalidConfigurationException('Scan '.$key.' must be a string or null.');
        }

        return $value;
    }

    private function value(string $key): mixed
    {
        $canonical = $this->config->get('vaulter.scan.'.$key);
        $alias = $this->config->get('vaulter-drive.uploads.scan.'.$key);
        if ($canonical !== null && $alias !== null && $canonical !== $alias) {
            throw new InvalidConfigurationException('Conflicting canonical and alias scan '.$key.'.');
        }

        return $canonical ?? $alias;
    }
}
