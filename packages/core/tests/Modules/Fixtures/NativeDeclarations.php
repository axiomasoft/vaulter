<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures;

use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\PluginModuleSpec;

/** Test-only adapter allowing compiler scenarios to keep their concise builder fixtures. */
trait NativeDeclarations
{
    private ?ModuleBuilder $declarations = null;

    private function declarations(): ModuleBuilder
    {
        if ($this->declarations === null) {
            $builder = new ModuleBuilder($this->id());
            $this->declare($builder);
            $builder->seal();
            $this->declarations = $builder;
        }

        return $this->declarations;
    }

    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: 'vaulter.'.$this->id()->value, version: '1.0.0', requires: array_map(static fn (string $id): string => 'vaulter.'.$id, array_keys($this->requires())));
    }

    public function register(PluginRegistrar $registrar): void {}

    public function moduleRuntime(): PluginModuleSpec
    {
        $b = $this->declarations();

        return new PluginModuleSpec(plugin: self::class, activation: $this->activation(), protocolVersion: $this->version(), requires: $this->requires(),
            routes: $b->routeFiles(), translations: $b->translationDirs(), commands: $b->commandClasses(),
            listeners: $b->listeners(), schedules: $b->schedules(), routeMiddleware: $b->routeMiddlewareAliases(),
            bindings: $b->bindingClasses(), events: $b->eventSources(), problems: $b->problemSources(), operations: $b->operationSources());
    }

    public function configRoot(): string
    {
        return array_key_first($this->declarations()->configs()) ?? '';
    }

    public function defaultConfigPath(): ?string
    {
        return array_values($this->declarations()->configs())[0] ?? null;
    }

    public function optionsClass(): ?string
    {
        return null;
    }

    public function configSectionClass(): ?string
    {
        return null;
    }

    public function configRules(): array
    {
        return [];
    }

    public function replacementMaps(): array
    {
        return [];
    }

    public function tablePrefix(): string
    {
        return '';
    }

    public function tableNouns(): array
    {
        return [];
    }

    public function migrationPath(): ?string
    {
        return $this->declarations()->schemaPaths()[0] ?? null;
    }

    public function schemaSetProviders(): array
    {
        return [];
    }

    public function schemaComponents(): array
    {
        return [];
    }

    public function definedCapabilities(): array
    {
        $values = [];
        foreach ($this->declarations()->capabilityDefinitions() as $d) {
            $values[$d->contract] = $d->cardinality->value;
        }

        return $values;
    }

    public function providedCapabilities(): array
    {
        $values = [];
        foreach ($this->declarations()->capabilityProviders() as $d) {
            $values[$d->contract] = $d->fromConfig() ? ['config' => $d->configKey, 'default' => $d->default] : $d->implementation;
        }

        return $values;
    }

    public function definedSlots(): array
    {
        $values = [];
        foreach ($this->declarations()->slotDefinitions() as $d) {
            $values[$d->contract] = ['kind' => $d->kind->value, 'cardinality' => $d->cardinality->value];
        }

        return $values;
    }

    public function slotContributions(): array
    {
        return array_map(static fn ($d): array => ['slot' => $d->slot, 'id' => $d->id, 'implementation' => $d->implementation, 'priority' => $d->priority, 'key' => $d->key], $this->declarations()->contributions());
    }
}
