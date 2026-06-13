<?php

declare(strict_types=1);

namespace DocVault\Models\Concerns;

use DocVault\Support\Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Switches the default DocVault models between auto-incrementing and ULID keys
 * based on config('docvault.id_type'). Host apps that swap in their own models
 * (e.g. a tenant-aware BaseModel) simply omit this trait.
 */
trait HasDocVaultKeys
{
    public static function bootHasDocVaultKeys(): void
    {
        static::creating(static function (Model $model): void {
            if (Config::usesUlids() && $model->getKey() === null) {
                $model->setAttribute($model->getKeyName(), (string) Str::ulid());
            }
        });
    }

    public function initializeHasDocVaultKeys(): void
    {
        if (Config::usesUlids()) {
            $this->keyType = 'string';
            $this->incrementing = false;
        }
    }
}
