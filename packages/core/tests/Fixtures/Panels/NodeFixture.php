<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Panels;

use Illuminate\Database\Eloquent\Model;
use Vaulter\Models\Concerns\HasVaulterKeys;

/**
 * A base "node" model that resolves its table/key from the shared v_* schema and
 * the global id strategy — the stand-in for a default-panel Vaulter\Models\Node.
 */
class NodeFixture extends Model
{
    use HasVaulterKeys;

    protected $table = 'v_nodes';

    protected $guarded = [];

    public $timestamps = false;
}
