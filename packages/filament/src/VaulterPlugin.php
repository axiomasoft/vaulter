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
     * The resource classes to register, each gated by its config toggle (default
     * on) — the Drive/permissions domain over the unified v_nodes tree.
     *
     * @return array<int, class-string>
     */
    private function enabledResources(): array
    {
        $candidates = [
            'storage' => StorageResource::class,
            'node' => NodeResource::class,
            'grant' => NodeGrantResource::class,
            'share' => NodeShareResource::class,
            'activity' => ActivityResource::class,
        ];

        $resources = [];

        foreach ($candidates as $key => $class) {
            if ($this->resourceEnabled($key)) {
                $resources[] = $class;
            }
        }

        return $resources;
    }

    /**
     * Whether a resource is enabled (config('vaulter-filament.resources.{key}'),
     * default true) — mirrors the existing config-driven pattern so a host may turn
     * off a surface it does not use.
     */
    private function resourceEnabled(string $key): bool
    {
        $value = config("vaulter-filament.resources.{$key}", true);

        return is_bool($value) ? $value : true;
    }
}
