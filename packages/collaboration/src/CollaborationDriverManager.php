<?php

declare(strict_types=1);

namespace Vaulter\Collaboration;

use Illuminate\Contracts\Container\Container;
use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\Configuration\CollaborationConfig;
use Vaulter\Collaboration\Enums\CollaborationDriverType;
use Vaulter\Documents\Contracts\CollaborationDriver;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Kernel;

/**
 * Resolves the active node-collaboration transport: the configuration key ('vaulter-documents.collaboration.driver')
 * is validated against the CollaborationDriverType, and the transport is the capability the documents module provides
 * (Hocuspocus until collaboration becomes a module of its own, P5). Distinct from Vaulter::driver(), which resolves the
 * LEGACY Document-bound collaboration driver — this is the node-tree lane.
 */
#[Internal]
final readonly class CollaborationDriverManager
{
    public function __construct(
        private Container $container,
        private CollaborationConfig $config,
    ) {}

    /**
     * The node-collaboration driver for the configured (or given) transport.
     */
    public function driver(?string $name = null): CollaborationDriver
    {
        // The name is validated whatever the transport; the transport itself is the module's capability.
        $this->type($name ?? $this->config->driver());

        return $this->container->make(Kernel::class)->capabilities()->require(CollaborationDriver::class);
    }

    private function type(string $name): CollaborationDriverType
    {
        $type = CollaborationDriverType::tryFrom($name);

        if ($type === null) {
            throw new VaulterException(
                sprintf('[Vaulter] Unknown collaboration driver [%s]. ', $name)
                .'Fix: set config(vaulter-collaboration.driver) to hocuspocus. '
                .'Docs: https://github.com/axiomasoft/vaulter/blob/main/docs/guides/errors.md#unknown-collaboration-driver',
            );
        }

        return $type;
    }
}
