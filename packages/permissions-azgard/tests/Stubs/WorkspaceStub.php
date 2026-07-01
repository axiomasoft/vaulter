<?php

declare(strict_types=1);

namespace Vaulter\Permissions\Azgard\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Vaulter\Permissions\Azgard\AzgardGuardAdapter;

/**
 * A minimal Eloquent model standing in for a host workspace/resource — the entity an
 * AzGuard scoped permission is checked against ({@see AzgardGuardAdapter::hasScopedPermission}).
 */
final class WorkspaceStub extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
