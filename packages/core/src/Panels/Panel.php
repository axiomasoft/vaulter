<?php

declare(strict_types=1);

namespace Vaulter\Panels;

use Vaulter\Support\Config;

/**
 * A storage panel built fluently (Filament-style mutable builder, NOT readonly).
 *
 * A Storage Panel is a named config bound to ANY host entity, living over ONE
 * shared v_* schema WITHOUT its own tables by default (zero migrations, D11). It
 * is the storage/isolation axis — strictly separate from the storage type (the
 * policy preset: private|team|public). Unset values fall back to the global
 * vaulter config, so a panel only declares what it overrides (override → prefix →
 * global).
 *
 * hasOwnTables() === false (the default) ⇒ the panel resolves the shared v_*
 * tables (Config::table) and no migrations are generated. A prefix or explicit
 * table map opts into the rare advanced per-panel-tables mode (D12).
 */
final class Panel
{
    /**
     * @param array<string, string>       $tableMap   base entity key => physical table name
     * @param array<string, class-string> $modelMap   entity key => model class
     * @param array<string, bool>         $features   feature flag overrides
     * @param array<int, class-string>    $extraPipes extra pipeline pipe classes (ingest/move/create)
     */
    public function __construct(
        private string $id = '',
        private ?string $hostModel = null,
        private bool $hasGrants = true,
        private string $storageType = 'private',
        private ?string $idStrategy = null,
        private ?string $tablePrefix = null,
        private array $tableMap = [],
        private array $modelMap = [],
        private array $features = [],
        private array $extraPipes = [],
        private ?string $resource = null,
    ) {}

    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function host(string $model): self
    {
        $this->hostModel = $model;

        return $this;
    }

    public function grants(): self
    {
        $this->hasGrants = true;

        return $this;
    }

    public function withoutGrants(): self
    {
        $this->hasGrants = false;

        return $this;
    }

    public function storageType(string $type): self
    {
        $this->storageType = $type;

        return $this;
    }

    public function idStrategy(string $strategy): self
    {
        $this->idStrategy = $strategy;

        return $this;
    }

    public function tablePrefix(string $prefix): self
    {
        $this->tablePrefix = $prefix;

        return $this;
    }

    /**
     * @param array<string, string> $map base entity key => physical table name
     */
    public function tables(array $map): self
    {
        $this->tableMap = $map;

        return $this;
    }

    /**
     * @param array<string, class-string> $map entity key => model class
     */
    public function models(array $map): self
    {
        $this->modelMap = $map;

        return $this;
    }

    /**
     * @param array<string, bool> $flags
     */
    public function features(array $flags): self
    {
        $this->features = [...$this->features, ...$flags];

        return $this;
    }

    public function feature(string $name, bool $on = true): self
    {
        $this->features[$name] = $on;

        return $this;
    }

    /**
     * Append extra pipeline pipe classes (ingest/move/create) for this panel.
     *
     * @param array<int, class-string> $pipes
     */
    public function pipes(array $pipes): self
    {
        $this->extraPipes = [...$this->extraPipes, ...$pipes];

        return $this;
    }

    public function resource(string $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function hostModel(): ?string
    {
        return $this->hostModel;
    }

    public function storageTypeKey(): string
    {
        return $this->storageType;
    }

    public function hasGrants(): bool
    {
        return $this->hasGrants;
    }

    /**
     * The explicit per-panel id strategy key, or null when the panel relies on
     * the global default (config('vaulter.ids.default')). On the shared schema a
     * non-null value is rejected at boot by the PanelRegistry (H.1).
     */
    public function idStrategyValue(): ?string
    {
        return $this->idStrategy;
    }

    /**
     * The explicit table prefix for this panel, or null when none was set.
     */
    public function tablePrefixValue(): ?string
    {
        return $this->tablePrefix;
    }

    /**
     * @return array<string, class-string>
     */
    public function modelMap(): array
    {
        return $this->modelMap;
    }

    /**
     * @return array<int, class-string>
     */
    public function pipesList(): array
    {
        return $this->extraPipes;
    }

    public function resourceClass(): ?string
    {
        return $this->resource;
    }

    /**
     * Whether this panel writes to its own per-panel tables rather than the
     * shared default schema: it carries a prefix or an explicit table map (D12).
     */
    public function hasOwnTables(): bool
    {
        return $this->tablePrefix !== null || $this->tableMap !== [];
    }

    /**
     * Resolve a physical table name for a base entity key (override → prefix →
     * global): explicit map wins, then a panel prefix ("{prefix}_{base}"), else
     * the shared default table via Config::table().
     */
    public function table(string $base): string
    {
        return $this->tableMap[$base]
            ?? ($this->tablePrefix !== null
                ? "{$this->tablePrefix}_{$base}"
                : Config::table($base));
    }

    /**
     * Resolve the model class bound to an entity key (override → global): the
     * panel map wins, else config('vaulter.models.{entity}') (swappable, D13).
     *
     * @return class-string|null
     */
    public function model(string $entity): ?string
    {
        if (isset($this->modelMap[$entity])) {
            return $this->modelMap[$entity];
        }

        $class = config("vaulter.models.{$entity}");

        return is_string($class) && class_exists($class) ? $class : null;
    }

    /**
     * The id strategy key for this panel: the per-panel override (own-tables mode
     * only, D12) falls back to the global default (config('vaulter.ids.default')).
     */
    public function idStrategyKey(): string
    {
        return $this->idStrategy ?? Config::idStrategy();
    }

    /**
     * Whether a feature is enabled for this panel. Panel-level overrides win over
     * the Vaulter per-feature defaults: grants follows hasGrants; versions, blobs,
     * tags, activity, uploads and extra_data are on; share_links, public_listing
     * and document_versions are off; reliability is on.
     */
    public function featureEnabled(string $name): bool
    {
        if (array_key_exists($name, $this->features)) {
            return $this->features[$name];
        }

        return match ($name) {
            'grants' => $this->hasGrants,
            'versions', 'blobs', 'tags', 'activity', 'uploads', 'extra_data', 'reliability' => true,
            'share_links', 'public_listing', 'document_versions' => false,
            default => false,
        };
    }
}
