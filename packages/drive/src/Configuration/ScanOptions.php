<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Drive\Content\ScanPolicySpec;
use Vaulter\Media\Enums\Cas\ScanMode;

/**
 * Immutable compiled global scan-policy options for `vaulter-drive.scan.*` (with legacy `uploads.scan.*` fallback).
 */
#[Api(since: '1.0')]
final readonly class ScanOptions
{
    public function __construct(
        public ScanMode $mode = ScanMode::Off,
        public ?string $scanner = null,
        public ?string $scannerKey = null,
        public ?string $scannerVersion = null,
        public ?string $policyVersion = null,
        public ?string $rolloutKey = null,
    ) {}

    public static function fromSections(
        mixed $raw,
        UploadsScanOptions $alias,
        string $path,
        ConfigurationValidator $validator,
    ): self {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, [
            'mode',
            'required',
            'scanner',
            'scanner_key',
            'scanner_version',
            'policy_version',
            'rollout_key',
        ], $path);

        $canonicalRequired = $raw['required'] ?? null;
        if ($canonicalRequired !== null && !is_bool($canonicalRequired)) {
            $validator->add($path.'.required', 'Scan required must be bool or null.');
            $canonicalRequired = null;
        }
        if ($canonicalRequired !== null && $alias->required !== null && $canonicalRequired !== $alias->required) {
            $validator->add($path.'.required', 'Conflicting canonical and alias scan required.');
        }
        $required = $canonicalRequired ?? $alias->required;

        $canonicalMode = null;
        $rawMode = $raw['mode'] ?? null;
        if ($rawMode instanceof ScanMode) {
            $canonicalMode = $rawMode;
        } elseif ($rawMode !== null) {
            $parsed = is_string($rawMode) ? ScanMode::tryFrom($rawMode) : null;
            if ($parsed === null) {
                $validator->add($path.'.mode', 'Unknown scan mode.');
            } else {
                $canonicalMode = $parsed;
            }
        }
        if ($canonicalMode !== null && $alias->mode !== null && $canonicalMode !== $alias->mode) {
            $validator->add($path.'.mode', 'Conflicting canonical and alias scan mode.');
        }
        $resolvedMode = $canonicalMode ?? $alias->mode;
        if ($required === true && $resolvedMode !== null && $resolvedMode !== ScanMode::AllContent) {
            $validator->add($path.'.mode', 'Scan required=true conflicts with the explicit mode.');
        }

        $mode = $required === true ? ScanMode::AllContent : ($resolvedMode ?? ScanMode::Off);

        return new self(
            mode: $mode,
            scanner: self::mergeString($raw['scanner'] ?? null, $alias->scanner, 'scanner', $path, $validator),
            scannerKey: self::mergeString($raw['scanner_key'] ?? null, $alias->scannerKey, 'scanner_key', $path, $validator),
            scannerVersion: self::mergeString($raw['scanner_version'] ?? null, $alias->scannerVersion, 'scanner_version', $path, $validator),
            policyVersion: self::mergeString($raw['policy_version'] ?? null, $alias->policyVersion, 'policy_version', $path, $validator),
            rolloutKey: self::mergeString($raw['rollout_key'] ?? null, $alias->rolloutKey, 'rollout_key', $path, $validator),
        );
    }

    public function globalSpec(): ScanPolicySpec
    {
        return new ScanPolicySpec(
            predicateKey: 'global',
            mode: $this->mode,
            scannerKey: $this->scannerKey ?? 'none',
            scannerVersion: $this->scannerVersion ?? 'none',
            policyVersion: $this->policyVersion ?? '1',
            rolloutKey: $this->rolloutKey,
        );
    }

    private static function mergeString(
        mixed $canonical,
        ?string $alias,
        string $key,
        string $path,
        ConfigurationValidator $validator,
    ): ?string {
        if ($canonical !== null && !is_string($canonical)) {
            $validator->add($path.'.'.$key, 'Scan '.$key.' must be a string or null.');

            return $alias;
        }
        if ($canonical !== null && $alias !== null && $canonical !== $alias) {
            $validator->add($path.'.'.$key, 'Conflicting canonical and alias scan '.$key.'.');
        }

        return $canonical ?? $alias;
    }
}
