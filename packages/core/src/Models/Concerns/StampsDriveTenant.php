<?php

declare(strict_types=1);

namespace Vaulter\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Tenancy\TenancyBoundary;

/** @internal Server-owned tenant stamping; host model scopes remain additional. */
trait StampsDriveTenant
{
    public static function bootStampsDriveTenant(): void
    {
        static::creating(static function (Model $row): void {
            if (!app(TenancyConfig::class)->enabled()) {
                return;
            }
            $config = app(VaulterConfig::class);
            $driveId = $row->getAttribute('drive_id');
            if ($driveId === null) {
                $nodeId = $row->getAttribute('node_id');
                if ($nodeId === null && $row->getAttribute('file_id') !== null) {
                    $fileClass = $config->model('file');
                    $nodeId = $fileClass::query()->whereKey($row->getAttribute('file_id'))->value('node_id');
                }
                $nodeClass = $config->model('node');
                $driveId = $nodeClass::query()->whereKey($nodeId)->value('drive_id');
            }
            $class = $config->model('drive');
            $drive = $class::query()->whereKey($driveId)->first();
            if (!$drive instanceof Drive || $drive->tenant_id === null) {
                throw NodeNotFoundException::forId('drive');
            }
            $drive = app(TenancyBoundary::class)->assertDrive($drive);
            $row->setAttribute('tenant_id', $drive->tenant_id);
        });
    }
}
