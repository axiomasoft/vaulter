<?php

declare(strict_types=1);

namespace Vaulter\Filament\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A fake host entity a storage is mounted on (Vaulter::drive($host)). Only its morph
 * (type + id) is persisted on the storage row, so it need not be a real table.
 */
final class TestHost extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}
