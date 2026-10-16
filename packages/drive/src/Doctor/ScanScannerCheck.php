<?php

declare(strict_types=1);

namespace Vaulter\Drive\Doctor;

use Illuminate\Contracts\Container\Container;
use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Kernel;
use Vaulter\Media\Contracts\Cas\ContentScanner;
use Vaulter\Media\Enums\Cas\ScanMode;
use Vaulter\Profiles\ProfilePolicy;
use Vaulter\Profiles\ProfileRegistry;

/** A scan policy that is on needs a scanner that resolves and answers to the identity the policy names. */
#[Internal]
final readonly class ScanScannerCheck implements DoctorCheck
{
    public function __construct(private Container $container, private ProfilePolicy $policy, private ProfileRegistry $profiles) {}

    public function key(): string
    {
        return 'drive.scan.scanner';
    }

    public function title(): string
    {
        return 'Content scanner';
    }

    public function run(DoctorContext $context): array
    {
        $enabled = [];
        foreach ($this->profiles->all() as $profile) {
            foreach ($this->policy->scanSpecs($profile) as $spec) {
                if ($spec->mode !== ScanMode::Off) {
                    $enabled[$spec->predicateKey.'|'.$spec->scannerKey.'|'.$spec->scannerVersion.'|'.$spec->policyVersion] = $spec;
                }
            }
        }
        if ($enabled === []) {
            return [DoctorFinding::ok(code: 'drive.scan.scanner_not_required', message: 'No scan policy is enabled.', evidence: ['mode' => ScanMode::Off->value])];
        }

        if (!$this->container->make(Kernel::class)->capabilities()->has(ContentScanner::class)) {
            return [DoctorFinding::error(code: 'drive.scan.scanner_missing', message: 'A scan policy is enabled but no ContentScanner is configured.', hint: 'set vaulter.scan.scanner to a ContentScanner class', evidence: ['policies' => count($enabled)])];
        }
        try {
            $scanner = $this->container->make(ContentScanner::class);
            $identity = [$scanner->key(), $scanner->version()];
        } catch (Throwable $exception) {
            return [DoctorFinding::error(code: 'drive.scan.scanner_unresolvable', message: 'The bound ContentScanner cannot be built.', hint: 'check the scanner binding and its dependencies', evidence: ['exception' => $exception::class])];
        }

        $findings = [];
        foreach ($enabled as $spec) {
            $evidence = ['predicate' => $spec->predicateKey, 'scanner_key' => $spec->scannerKey, 'scanner_version' => $spec->scannerVersion, 'policy_version' => $spec->policyVersion];
            if ($spec->scannerKey === 'none' || $spec->scannerVersion === 'none') {
                $findings[] = DoctorFinding::error(code: 'drive.scan.identity_missing', message: 'The scan policy "'.$spec->predicateKey.'" has no scanner_key or scanner_version.', hint: 'set vaulter.scan.scanner_key and vaulter.scan.scanner_version to the scanner\'s own identity', evidence: $evidence);
            } elseif ($identity !== [$spec->scannerKey, $spec->scannerVersion]) {
                $findings[] = DoctorFinding::error(code: 'drive.scan.identity_mismatch', message: 'The bound scanner answers to another key or version than the scan policy "'.$spec->predicateKey.'" names.', hint: 'align vaulter.scan.scanner_key / scanner_version with the scanner', evidence: $evidence + ['bound_key' => $identity[0], 'bound_version' => $identity[1]]);
            }
        }

        return $findings === [] ? [DoctorFinding::ok(code: 'drive.scan.scanner_ok', message: 'The scanner resolves and matches every enabled scan policy.', evidence: ['policies' => count($enabled), 'scanner_key' => $identity[0], 'scanner_version' => $identity[1]])] : $findings;
    }
}
