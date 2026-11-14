<?php

declare(strict_types=1);

namespace Vaulter\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\IdsConfig;
use Vaulter\Contracts\IdStrategy;
use Vaulter\Models\Casts\IntegerKey;
use Vaulter\Schema\AutoIncrementIds;
use Vaulter\Schema\IdStrategyResolver;

/**
 * Drives the default Vaulter models' key handling from the resolved IdStrategy
 * (ulid|uuid7|bigint). String, non-incrementing keys for ulid/uuid7; the
 * database assigns auto-increment bigint keys. Host apps that swap in their own
 * models (e.g. a tenant-aware BaseModel) simply omit this trait.
 */
#[Api]
trait HasVaulterKeys
{
    private bool $vaulterBigintKeys = false;

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
        $strategy = $this->vaulterIdStrategy();
        $keyType = $this->getKeyType();
        if ($keyType === 'int') {
            // Native Table metadata is applied after trait initialization; match its effective key type here.
            $keyType = static::resolveClassAttribute(Table::class, 'keyType') ?? $keyType;
        }
        $traits = class_uses_recursive($this);
        $nativeStringKey = in_array(HasUuids::class, $traits, true) || in_array(HasUlids::class, $traits, true);
        if ($strategy instanceof AutoIncrementIds && !$nativeStringKey && in_array($keyType, ['int', 'integer'], true)) {
            $this->vaulterBigintKeys = true;
            // String metadata keeps whereKey(array) and eager relations away from Laravel's lossy integer-IN cast.
            // The explicit PK cast below still returns native integers whenever PHP can represent them.
            $this->keyType = 'string';

            return;
        }
        if ($strategy->modelConcern() === null) {
            return;
        }

        $this->keyType = 'string';
        $this->incrementing = false;
    }

    /** @return array<string, string> */
    #[Internal]
    #[\Override]
    public function getCasts(): array
    {
        $casts = parent::getCasts();
        if ($this->vaulterBigintKeys) {
            // Resolve after every initializer, including a native Table attribute that changes the primary-key name.
            $casts[$this->getKeyName()] = IntegerKey::class;
        }

        return $casts;
    }

    /**
     * @param Builder<static>      $query
     * @param array<string, mixed> $attributes
     */
    #[Internal]
    #[\Override]
    protected function insertAndSetId(Builder $query, $attributes): void
    {
        $connection = $query->getConnection();
        if (!$this->vaulterBigintKeys || !$connection instanceof MySqlConnection) {
            parent::insertAndSetId($query, $attributes);

            return;
        }
        $key = $this->getKeyName();
        // MySqlProcessor narrows its result to int. The connection retained PDO's original id before that cast.
        $query->insertGetId($attributes, $key);
        $this->setAttribute($key, $connection->getLastInsertId());
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
