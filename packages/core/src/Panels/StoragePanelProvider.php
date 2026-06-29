<?php

declare(strict_types=1);

namespace Vaulter\Panels;

/**
 * Base class for a storage panel provider (D11). A consumer subclasses this and
 * configures the fluent Panel in panel(); the panel is registered into the
 * central PanelRegistry by its id. Optional hooks let a panel enrich the node
 * pipeline and the node representation without forking the package.
 *
 * Providers are registered by FQCN only (config('vaulter.panels')), so the
 * config stays config-cache-safe (no closures).
 */
abstract class StoragePanelProvider
{
    /**
     * Configure the panel for this provider. Implementations set the id via
     * $panel->id(...) and any per-panel overrides.
     */
    abstract public function panel(Panel $panel): Panel;

    /**
     * Build the panel value object from a fresh builder.
     */
    public function resolve(): Panel
    {
        return $this->panel(new Panel);
    }

    /**
     * Extra attributes to persist on a node being stored (panel hook, no-op by
     * default).
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function fillNode(array $data): array
    {
        return [];
    }

    /**
     * Extra attributes to expose when presenting a node (panel hook, no-op by
     * default).
     *
     * @param  array<string, mixed> $node
     * @return array<string, mixed>
     */
    public function presentNode(array $node): array
    {
        return [];
    }

    /**
     * Extra pipeline pipe classes to append to the node pipeline (panel hook,
     * no-op by default).
     *
     * @return array<int, class-string>
     */
    public function pipes(): array
    {
        return [];
    }

    /**
     * Run after the panel has been registered (panel hook, no-op by default).
     */
    public function booted(Panel $panel): void {}
}
