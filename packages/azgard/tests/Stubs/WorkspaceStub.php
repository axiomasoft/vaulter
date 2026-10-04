<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

/**
 * A minimal Eloquent model standing in for a host workspace/resource — the entity an
 * AzGuard scoped permission is checked against ({@see AzgardGuardAdapter::hasScopedPermission}).
 */
final class WorkspaceStub extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
