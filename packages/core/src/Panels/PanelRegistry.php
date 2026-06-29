<?php

declare(strict_types=1);

namespace Vaulter\Panels;

use Illuminate\Database\Eloquent\Model;
use Vaulter\Exceptions\InvalidPanelException;
use Vaulter\Exceptions\UnknownPanelException;

/**
 * The central registry of resolved storage panels: one place that owns every
 * Panel value object keyed by its id. Providers (FQCN from config) are resolved
 * into panels here; consumers look a panel up by name. Held as a container
 * singleton; config hydration happens via fromConfig().
 *
 * Hardening (H.1): every panel is validated at boot — referenced FQCNs (models,
 * pipes, resource) must exist and satisfy their contract, and a per-panel id
 * strategy is rejected on the shared schema (hasOwnTables() === false) — so a
 * broken panel fails loud at boot rather than corrupting a request later.
 */
final class PanelRegistry
{
    /**
     * @var array<string, Panel>
     */
    private array $panels = [];

    public function register(Panel $panel): void
    {
        $this->validate($panel);

        $this->panels[$panel->getId()] = $panel;
    }

    public function registerProvider(StoragePanelProvider $provider): void
    {
        $panel = $provider->resolve();

        $this->register($panel);

        $provider->booted($panel);
    }

    /**
     * Build a registry from a list of provider FQCN plus the default provider.
     * The default is registered FIRST so an explicit panel may override it.
     *
     * @param array<int, class-string<StoragePanelProvider>> $providerClasses
     * @param class-string<StoragePanelProvider>             $defaultClass
     */
    public static function fromConfig(array $providerClasses, string $defaultClass): self
    {
        $registry = new self;

        $registry->registerProvider(new $defaultClass);

        foreach ($providerClasses as $providerClass) {
            $registry->registerProvider(new $providerClass);
        }

        return $registry;
    }

    public function get(string $name): Panel
    {
        return $this->panels[$name]
            ?? throw UnknownPanelException::forName($name, array_keys($this->panels));
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->panels);
    }

    /**
     * @return array<string, Panel>
     */
    public function all(): array
    {
        return $this->panels;
    }

    public function default(): Panel
    {
        return $this->get('default');
    }

    /**
     * Reject an incoherent panel at boot (H.1): bad FQCNs and a per-panel id
     * strategy on the shared schema.
     */
    private function validate(Panel $panel): void
    {
        $id = $panel->getId();

        if ($panel->idStrategyValue() !== null && !$panel->hasOwnTables()) {
            throw InvalidPanelException::idStrategyWithoutOwnTables($id);
        }

        foreach ($panel->modelMap() as $entity => $modelClass) {
            $this->assertClassIs($id, "model [{$entity}]", $modelClass, Model::class);
        }

        foreach ($panel->pipesList() as $pipeClass) {
            $this->assertClassExists($id, 'pipe', $pipeClass);
        }

        $resource = $panel->resourceClass();

        if ($resource !== null) {
            $this->assertClassExists($id, 'resource', $resource);
        }
    }

    private function assertClassExists(string $panel, string $kind, string $fqcn): void
    {
        if (!class_exists($fqcn)) {
            throw InvalidPanelException::missingClass($panel, $kind, $fqcn);
        }
    }

    private function assertClassIs(string $panel, string $kind, string $fqcn, string $contract): void
    {
        $this->assertClassExists($panel, $kind, $fqcn);

        if (!is_a($fqcn, $contract, allow_string: true)) {
            throw InvalidPanelException::notInstanceOf($panel, $kind, $fqcn, $contract);
        }
    }
}
