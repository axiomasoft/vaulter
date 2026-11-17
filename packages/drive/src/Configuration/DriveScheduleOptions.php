<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Configuration\ScheduleFrequency;

/**
 * Immutable compiled scheduler frequencies for `vaulter-drive.schedule.*`.
 */
#[Api(since: '1.0')]
final readonly class DriveScheduleOptions
{
    public function __construct(
        public ?string $changesPrune = 'dailyAt:03:20',
        public ?string $uploadsSweep = 'hourlyAt:15',
        public ?string $trashPurge = 'dailyAt:03:30',
        public ?string $drivesPurge = 'hourlyAt:25',
        public ?string $revisionsPrune = 'dailyAt:03:40',
        public ?string $scanReconcile = 'everyFiveMinutes',
        public ?string $drivesRecount = null,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, [
            'changes_prune',
            'uploads_sweep',
            'trash_purge',
            'drives_purge',
            'revisions_prune',
            'scan_reconcile',
            'drives_recount',
        ], $path);

        return new self(
            changesPrune: self::validate($raw, 'changes_prune', 'dailyAt:03:20', $path, $validator),
            uploadsSweep: self::validate($raw, 'uploads_sweep', 'hourlyAt:15', $path, $validator),
            trashPurge: self::validate($raw, 'trash_purge', 'dailyAt:03:30', $path, $validator),
            drivesPurge: self::validate($raw, 'drives_purge', 'hourlyAt:25', $path, $validator),
            revisionsPrune: self::validate($raw, 'revisions_prune', 'dailyAt:03:40', $path, $validator),
            scanReconcile: self::validate($raw, 'scan_reconcile', 'everyFiveMinutes', $path, $validator),
            drivesRecount: self::validate($raw, 'drives_recount', null, $path, $validator),
        );
    }

    /** @param array<array-key, mixed> $raw */
    private static function validate(
        array $raw,
        string $key,
        ?string $default,
        string $path,
        ConfigurationValidator $validator,
    ): ?string {
        $value = array_key_exists($key, $raw) ? $raw[$key] : $default;
        if ($value === null) {
            return null;
        }

        try {
            ScheduleFrequency::parse($path.'.'.$key, $value);
        } catch (InvalidConfiguration $exception) {
            $validator->add($path.'.'.$key, $exception->getMessage());

            return null;
        }

        return is_string($value) ? $value : null;
    }
}
