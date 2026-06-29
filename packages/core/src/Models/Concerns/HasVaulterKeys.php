<?php

declare(strict_types=1);

namespace Vaulter\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Vaulter\Support\Config;

/**
 * Switches the default Vaulter models between auto-incrementing and ULID keys
 * based on config('vaulter.id_type'). Host apps that swap in their own models
 * (e.g. a tenant-aware BaseModel) simply omit this trait.
 */
trait HasVaulterKeys
{
    public static function bootHasVaulterKeys(): void
    {
        static::creating(static function (Model $model): void {
            if (Config::usesUlids() && $model->getKey() === null) {
                $model->setAttribute($model->getKeyName(), (string) Str::ulid());
            }
        });
    }

    public function initializeHasVaulterKeys(): void
    {
        if (Config::usesUlids()) {
            $this->keyType = 'string';
            $this->incrementing = false;
        }
    }
}
