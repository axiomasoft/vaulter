<?php

declare(strict_types=1);

namespace Vaulter\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Override;
use Vaulter\Attributes\Api;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Filament\Configuration\FilamentConfig;
use Vaulter\Filament\Resources\ActivityResource;
use Vaulter\Filament\Resources\DriveResource;
use Vaulter\Filament\Resources\NodeGrantResource;
use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Resources\NodeShareResource;

/**
 * Vaulter Filament plugin (Filament 5).
 *
 * Register it in your PanelProvider:
 *
 *   $panel->plugins([ VaulterPlugin::make() ]);
 *
 * It registers the Drive/permissions resources (Drive, Node, NodeGrant,
 * NodeShare, Activity), each toggleable through
 * FilamentConfig.
 *
 * Resources show snapshots read through the drive entry point and the authorized drive
 * handles (`Vaulter::drives()`, the handle's node, access, share and activity operations), so
 * what a panel admin sees and does is decided by the drive, whichever models the host swapped.
 */
#[Api]
final class VaulterPlugin implements Plugin
{
    public static function make(): static
    {
        return new self;
    }

    /**
     * Localize the unsafe getPlugin() cast (Filament 5): the panel's
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
     * . Only `activity` qualifies today: `drive`/
     * `node` have no corresponding feature (drives/nodes are the unconditional
     * schema roots), and `grant`/`share` map to `grants`/`share_links` — both
     * consulted when a per-panel schema was built DYNAMICALLY, but the package's OWN
     * static migrations always create
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
            'drive' => [DriveResource::class, null],
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
     * (FilamentConfig::resourceEnabled, default true) AND — when the
     * resource's schema is behind a core feature flag — that flag
     * (`app(VaulterConfig::class)->featureEnabled()`). Without the AND-condition, a host that disabled
     * the `activity` subsystem but left `ActivityResource`'s own default-on toggle
     * untouched would keep the resource registered against a subsystem it turned
     * off, turning a legitimate "subsystem off" configuration into a broken
     * navigation entry instead of the resource simply not appearing (
     * optional subsystems must degrade, not crash).
     */
    private function resourceEnabled(string $key, ?string $feature): bool
    {
        $toggledOn = app(FilamentConfig::class)->resourceEnabled($key);

        if (!$toggledOn) {
            return false;
        }

        return $feature === null || app(VaulterConfig::class)->featureEnabled($feature);
    }
}
