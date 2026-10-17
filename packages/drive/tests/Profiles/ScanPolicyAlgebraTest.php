<?php

declare(strict_types=1);

use Vaulter\Drive\Configuration\ScanConfig;
use Vaulter\Drive\Content\ScanPolicySpec;
use Vaulter\Drive\Exceptions\Profiles\InvalidProfileException;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Drive\Profiles\UploadPolicy;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\Enums\Cas\ScanMode;

it('SEC-CONFIG-alias normalizes null defaults and required=true to all_content', function (): void {
    $scan = app(ScanConfig::class);
    expect($scan->mode())->toBe(ScanMode::Off);
    config()->set('vaulter-drive.uploads.scan.required', true);
    expect($scan->mode())->toBe(ScanMode::AllContent);
    config()->set('vaulter-drive.scan.required', true);
    expect($scan->mode())->toBe(ScanMode::AllContent);
    config()->set('vaulter-drive.scan.mode', 'off');
    expect(fn () => $scan->mode())->toThrow(InvalidConfigurationException::class);
});

it('SEC-CONFIG-alias refuses explicit unequal canonical and alias values', function (string $key, mixed $canonical, mixed $alias): void {
    config()->set('vaulter-drive.scan.'.$key, $canonical);
    config()->set('vaulter-drive.uploads.scan.'.$key, $alias);
    $scan = app(ScanConfig::class);
    $scan->globalSpec();
    $scan->scanner();
})->with([
    ['mode', 'all_content', 'off'], ['required', true, false], ['scanner', 'ScannerA', 'ScannerB'],
    ['scanner_key', 'a', 'b'], ['scanner_version', '1', '2'], ['policy_version', '1', '2'], ['rollout_key', 'a', 'b'],
])->throws(InvalidConfigurationException::class, 'Conflicting');

it('SEC-CONFIG-alias permits false with explicit all_content and equal duplicate identifiers', function (): void {
    config()->set('vaulter-drive.scan', ['mode' => 'all_content', 'required' => false, 'policy_version' => '2']);
    config()->set('vaulter-drive.uploads.scan', ['mode' => 'all_content', 'required' => false, 'policy_version' => '2']);
    expect(app(ScanConfig::class)->globalSpec()->mode)->toBe(ScanMode::AllContent)
        ->and(app(ScanConfig::class)->globalSpec()->policyVersion)->toBe('2');
});

it('SEC-POLICY-two-boundaries retains independent predicates rather than max(mode)', function (): void {
    $global = new ScanPolicySpec('global', ScanMode::NewContentOnly, 'scanner', '1', '1', 'g');
    $profile = new ScanPolicySpec('profile:p', ScanMode::NewContentOnly, 'scanner', '1', '2', 'p');
    expect($global->requiresScan(15, 20))->toBeFalse()->and($profile->requiresScan(15, 12))->toBeTrue()
        ->and($global->requiresScan(21, 20))->toBeTrue()->and($profile->requiresScan(21, 12))->toBeTrue();
    expect(fn () => $global->requiresScan(0, null))->toThrow(InvalidConfigurationException::class, 'boundary');
});

it('SEC-POLICY-two-boundaries never subtracts global all_content through profile off', function (): void {
    config()->set('vaulter-drive.scan.mode', 'all_content');
    $profile = (new Profile)->key('p')->uploads(new UploadPolicy(requireScan: false, scanMode: ScanMode::Off));
    [$global, $own] = app(ProfilePolicy::class)->scanSpecs($profile);
    expect($global->requiresScan(0, null))->toBeTrue()->and($own->requiresScan(0, null))->toBeFalse();
});

it('inherits the global mode and supports an independent stronger profile version', function (): void {
    config()->set('vaulter-drive.scan.mode', 'new_content_only');
    [$global, $own] = app(ProfilePolicy::class)->scanSpecs((new Profile)->key('p'));
    expect($own->mode)->toBe($global->mode)->and($own->specKey())->toBe($global->specKey());
    [$global, $own] = app(ProfilePolicy::class)->scanSpecs((new Profile)->key('p')->uploads(new UploadPolicy(requireScan: true, scanPolicyVersion: '2')));
    expect($own->mode)->toBe(ScanMode::AllContent)->and($own->specKey())->not->toBe($global->specKey());
});

it('rejects contradictory required profile modes', function (ScanMode $mode): void {
    new UploadPolicy(requireScan: true, scanMode: $mode);
})->with([ScanMode::Off, ScanMode::NewContentOnly])->throws(InvalidProfileException::class);

it('keeps spec identity compatible across enforcement mode and rollout changes', function (): void {
    $off = new ScanPolicySpec('global', ScanMode::Off, 'scanner', 'v1', 'v1');
    $all = new ScanPolicySpec('global', ScanMode::AllContent, 'scanner', 'v1', 'v1', 'rollout');
    expect($off->specKey())->toBe($all->specKey());
});
