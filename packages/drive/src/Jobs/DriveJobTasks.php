<?php

declare(strict_types=1);

namespace Vaulter\Drive\Jobs;

use Vaulter\Attributes\Api;
use Vaulter\Drive\Jobs\Uploads\ScanFileRevision;
use Vaulter\Jobs\JobRegistry;
use Vaulter\Jobs\JobRequest;
use Vaulter\Jobs\PublicationContext;

/** Public task mapping owned by Drive; integration bridges never import private job classes. */
#[Api]
final class DriveJobTasks
{
    public static function registry(): JobRegistry
    {
        return new JobRegistry([
            'vaulter.drive:purge' => static function (JobRequest $request, ?PublicationContext $origin): object {
                if (array_keys($request->refs) !== ['driveId']) {
                    throw new \InvalidArgumentException('Invalid purge refs.');
                }

                return new PurgeDriveJob($request->refs['driveId'], $origin);
            },
            'vaulter.drive:scan' => static function (JobRequest $request, ?PublicationContext $origin): object {
                $refs = $request->refs;
                $keys = array_keys($refs);
                sort($keys);
                if (!in_array($keys, [['driveId', 'generation', 'obligationId'], ['driveId', 'generation', 'obligationId', 'tenantId']], true)
                    || !preg_match('/^[1-9][0-9]{0,18}$/D', $refs['generation'])
                    || (string) (int) $refs['generation'] !== $refs['generation']) {
                    throw new \InvalidArgumentException('Invalid scan refs.');
                }

                return new ScanFileRevision($refs['obligationId'], (int) $refs['generation'], $refs['driveId'],
                    $refs['tenantId'] ?? null, $origin);
            },
        ]);
    }
}
