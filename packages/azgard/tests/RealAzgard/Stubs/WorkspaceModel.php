<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Tests\RealAzgard\Stubs;

use Illuminate\Database\Eloquent\Model;

/**
 * A real Eloquent entity an AzGuard scoped role is attached to (the scope subject a
 * host resource — project / workspace — would be).
 */
final class WorkspaceModel extends Model
{
    public $timestamps = false;

    protected $table = 'workspaces';

    protected $guarded = [];
}
