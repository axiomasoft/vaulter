<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Documents;

/**
 * The front-end mount descriptor a document handler returns for a file (master plan
 * §4.5 / §7): which Vue component the client should mount, the route it opens the
 * node through, and any extra props that component needs. The NodeResource embeds
 * this (alongside the capabilities) so the Vue client routes a node to the right
 * editor / preview / download surface WITHOUT hard-coding mime logic on the client.
 *
 * Immutable. `props` is an arbitrary, serialisable map the handler decides (e.g. the
 * TipTap extension list, the preview mode, a feature flag).
 */
final readonly class HandlerUi
{
    /**
     * @param array<string, mixed> $props
     */
    public function __construct(
        public string $component,
        public ?string $route = null,
        public array $props = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'route' => $this->route,
            'props' => $this->props,
        ];
    }
}
