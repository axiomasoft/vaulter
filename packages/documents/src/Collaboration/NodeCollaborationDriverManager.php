<?php

declare(strict_types=1);

namespace Vaulter\Documents\Collaboration;

use Illuminate\Contracts\Container\Container;
use Vaulter\Documents\Contracts\Collaboration\NodeCollaborationDriver;
use Vaulter\Documents\Enums\CollaborationDriverType;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Support\Config;

/**
 * Resolves the active node-collaboration transport (M4b) from
 * config('vaulter.collaboration.driver'). The driver class map is keyed by the
 * CollaborationDriverType so adding a transport is one enum case + one binding, no
 * stringly-typed lookup. Distinct from VaulterManager::driver(), which resolves the
 * LEGACY Document-bound collaboration driver — this is the node-tree lane.
 */
final readonly class NodeCollaborationDriverManager
{
    public function __construct(
        private Container $container,
    ) {}

    /**
     * The node-collaboration driver for the configured (or given) transport.
     */
    public function driver(?string $name = null): NodeCollaborationDriver
    {
        $type = $this->type($name ?? Config::collaborationDriverName());

        $class = match ($type) {
            CollaborationDriverType::Reverb => ReverbNodeCollaborationDriver::class,
            CollaborationDriverType::Hocuspocus => HocuspocusNodeCollaborationDriver::class,
        };

        return $this->container->make($class);
    }

    private function type(string $name): CollaborationDriverType
    {
        $type = CollaborationDriverType::tryFrom($name);

        if ($type === null) {
            throw new VaulterException(
                sprintf('[Vaulter] Unknown collaboration driver [%s]. ', $name)
                .'Fix: set config(vaulter.collaboration.driver) to one of: reverb, hocuspocus. '
                .'Docs: https://github.com/axioma-studio/vaulter/blob/main/docs/guide/errors.md#unknown-collaboration-driver',
            );
        }

        return $type;
    }
}
