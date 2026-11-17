<?php

declare(strict_types=1);

namespace Vaulter\Testing\Fixtures;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Internal;

/**
 * An actor that is identified by its morph reference alone and holds no host
 * abilities, so a decision about it can only come from Vaulter's own grants.
 */
/**
 * @property int $id
 */
#[Internal]
final class FixtureActor extends Model implements Authenticatable, Authorizable
{
    private static int $sequence = 0;

    protected $guarded = [];

    public $timestamps = false;

    public static function next(): self
    {
        $actor = new self;
        $actor->forceFill(['id' => 800_000 + ++self::$sequence]);
        $actor->exists = true;

        return $actor;
    }

    /**
     * @param iterable<mixed>|string|\UnitEnum $abilities
     * @param array<mixed>|mixed               $arguments
     */
    public function can($abilities, $arguments = []): bool
    {
        return false;
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
