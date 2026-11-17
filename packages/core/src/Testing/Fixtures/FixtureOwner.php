<?php

declare(strict_types=1);

namespace Vaulter\Testing\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Internal;

/**
 * A host entity that only exists as a morph reference, for fixtures that need a drive
 * owner but no host model.
 */
#[Internal]
final class FixtureOwner extends Model
{
    private static int $sequence = 0;

    protected $guarded = [];

    public $timestamps = false;

    public static function next(): self
    {
        $owner = new self;
        $owner->forceFill(['id' => 900_000 + ++self::$sequence]);
        $owner->exists = true;

        return $owner;
    }
}
