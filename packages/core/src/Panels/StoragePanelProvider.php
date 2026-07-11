<?php

declare(strict_types=1);

namespace Vaulter\Panels;

/**
 * Base class for a storage panel provider (D11). A consumer subclasses this and
 * configures the fluent Panel in panel(); the panel is registered into the
 * central PanelRegistry by its id.
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
     * @unimplemented
     *
     * F-P3.2-02, W4/P4.4: declared and validated (Panel::pipesList() checks the
     * referenced classes exist, see PanelRegistry::validate()) but NOT wired to any
     * node-creation path — no caller passes stored attributes through this hook
     * anywhere in the package. Overriding it today is a silent no-op. Wiring it
     * into the real node pipeline is a product feature pending owner "go" (see
     * plans/VLT-REMED/handoff.md deferred list), not this hook's contract.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function fillNode(array $data): array
    {
        return [];
    }

    /**
     * @unimplemented
     *
     * F-P3.2-02, W4/P4.4: same status as {@see fillNode()} — declared, never
     * called by any node-representation path in the package. Silent no-op today.
     *
     * @param  array<string, mixed> $node
     * @return array<string, mixed>
     */
    public function presentNode(array $node): array
    {
        return [];
    }

    /**
     * @unimplemented
     *
     * F-P3.2-02, W4/P4.4: same status as {@see fillNode()}. The returned classes
     * are validated to exist at boot (PanelRegistry::validate()) but are never
     * read/injected into any pipeline — validation creates the appearance of a
     * working seam without one existing yet.
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
