<?php

declare(strict_types=1);

namespace Vaulter\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Override;
use Vaulter\Filament\Resources\ActivityResource;
use Vaulter\Filament\Resources\NodeGrantResource;
use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Resources\NodeShareResource;
use Vaulter\Filament\Resources\StorageResource;
use Vaulter\Support\Config;

/**
 * Vaulter Filament plugin (Filament 5).
 *
 * Register it in your PanelProvider:
 *
 *   $panel->plugins([ VaulterPlugin::make() ]);
 *
 * It registers two families of resources, each toggleable through
 * config('vaulter-filament.resources.*'):
 *   - the NEW Drive/permissions domain (Storage, Node, NodeGrant, NodeShare,
 *     Activity) built on the headless core (M1–M4);
 *   - the LEGACY document-centric resources (Document, Folder, Share), kept
 *     working for hosts still on that surface.
 *
 * Resources resolve their model through Vaulter\Support\Config::*Model(), so a host
 * that swaps a model keeps being governed by these resources.
 */
final class VaulterPlugin implements Plugin
{
    public static function make(): static
    {
        return new self;
    }

    /**
     * Localize the unsafe getPlugin() cast (H.5 / Filament 5): the panel's
     * getPlugin() returns the Plugin contract, so a caller that needs the concrete
     * plugin (its config, its toggles) gets a single typed accessor instead of an
     * inline cast scattered across the codebase.
     */
    public static function current(Panel $panel): self
    {
        /** @var self $plugin */
        $plugin = $panel->getPlugin('vaulter');

        return $plugin;
    }

    #[Override]
    public function getId(): string
    {
        return 'vaulter';
    }

    #[Override]
    public function register(Panel $panel): void
    {
        $panel->resources($this->enabledResources());
    }

    #[Override]
    public function boot(Panel $panel): void
    {
        //
    }

    /**
     * The resource classes to register, each gated by its OWN config toggle
     * (default on — the Drive/permissions domain over the unified v_nodes tree) AND,
     * where the resource's schema is behind a core SUBSYSTEM feature flag (one that a
     * host may disable independently of its own resource toggle), that flag too
     * (VLT-REMED P3.1 / F-P1.10-03, D13). Only `activity` qualifies today: `storage`/
     * `node` have no corresponding feature (storages/nodes are the unconditional
     * schema roots), and `grant`/`share` map to `grants`/`share_links` — both
     * consulted by `SchemaBuilder::entityEnabled()` for DYNAMICALLY-built
     * per-panel schemas, but the package's OWN static migrations always create
     * `v_node_grants`/`v_node_shares` unconditionally (only the ROUTES/UI are
     * feature-gated for them) — gating those two resources here would hide them by
     * default (`share_links` defaults `false`) with no missing-table risk to guard
     * against, which is not this finding's failure mode.
     *
     * @return array<int, class-string>
     */
    private function enabledResources(): array
    {
        $candidates = [
            // key => [resource class, core feature flag the schema needs (null = none)]
            'storage' => [StorageResource::class, null],
            'node' => [NodeResource::class, null],
            'grant' => [NodeGrantResource::class, null],
            'share' => [NodeShareResource::class, null],
            'activity' => [ActivityResource::class, 'activity'],
        ];

        $resources = [];

        foreach ($candidates as $key => [$class, $feature]) {
            if ($this->resourceEnabled($key, $feature)) {
                $resources[] = $class;
            }
        }

        return $resources;
    }

    /**
     * Whether a resource is enabled: its OWN config toggle
     * (config('vaulter-filament.resources.{key}'), default true) AND — when the
     * resource's schema is behind a core feature flag — that flag
     * (`Config::featureEnabled()`). Without the AND-condition, a host that disabled
     * the `activity` subsystem but left `ActivityResource`'s own default-on toggle
     * untouched would keep the resource registered against a subsystem it turned
     * off, turning a legitimate "subsystem off" configuration into a broken
     * navigation entry instead of the resource simply not appearing (F-P1.10-03,
     * D13: optional subsystems must degrade, not crash).
     */
    private function resourceEnabled(string $key, ?string $feature): bool
    {
        $value = config("vaulter-filament.resources.{$key}", true);
        $toggledOn = is_bool($value) ? $value : true;

        if (!$toggledOn) {
            return false;
        }

        return $feature === null || Config::featureEnabled($feature);
    }
}
