<?php

declare(strict_types=1);

namespace Vaulter\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Vaulter\Configuration\IdsConfig;
use Vaulter\Contracts\IdStrategy;
use Vaulter\Schema\IdStrategyResolver;

/**
 * Drives the default Vaulter models' key handling from the resolved IdStrategy
 * (ulid|uuid7|bigint). String, non-incrementing keys for ulid/uuid7; the
 * database assigns auto-increment bigint keys. Host apps that swap in their own
 * models (e.g. a tenant-aware BaseModel) simply omit this trait.
 */
trait HasVaulterKeys
{
    public static function bootHasVaulterKeys(): void
    {
        static::creating(static function (Model $model): void {
            if ($model->getKey() !== null) {
                return;
            }

            // The seam lives on the concrete model (this trait), not Model.
            $key = $model instanceof self ? $model->vaulterIdStrategyKey() : app(IdsConfig::class)->strategy();
            $newId = app(IdStrategyResolver::class)->resolve($key)->newId();

            if ($newId === null) {
                return;
            }

            $model->setAttribute($model->getKeyName(), $newId);
        });
    }

    public function initializeHasVaulterKeys(): void
    {
        if ($this->vaulterIdStrategy()->modelConcern() === null) {
            return;
        }

        $this->keyType = 'string';
        $this->incrementing = false;
    }

    /**
     * Seam letting subclasses / panels override the strategy key (e.g. a
     * per-panel strategy in own-tables mode). Defaults to the global strategy.
     */
    protected function vaulterIdStrategyKey(): string
    {
        return app(IdsConfig::class)->strategy();
    }

    private function vaulterIdStrategy(): IdStrategy
    {
        return app(IdStrategyResolver::class)->resolve($this->vaulterIdStrategyKey());
    }
}
