<?php

declare(strict_types=1);

namespace Vaulter\Testing\Fixtures\Panels;

use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Internal;

/**
 * A fake host entity a storage panel can embed into (Vaulter::drive($project)).
 */
#[Internal]
final class FakeProject extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}
