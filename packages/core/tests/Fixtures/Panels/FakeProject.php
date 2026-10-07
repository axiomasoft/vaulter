<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Panels;

use Illuminate\Database\Eloquent\Model;

/**
 * A fake host entity a storage panel can embed into (Vaulter::drive($project)).
 */
final class FakeProject extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}
