<?php

declare(strict_types=1);

namespace Vaulter\Documents\Collaboration;

use Illuminate\Contracts\Container\Container;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Contracts\CollaborationDriver;
use Vaulter\Documents\Enums\Collaboration\CollaborationDriverType;
use Vaulter\Exceptions\VaulterException;

/**
 * Resolves the active node-collaboration transport from
 * the configuration key ('vaulter-documents.collaboration.driver'). The driver class map is keyed by the
 * CollaborationDriverType so adding a transport is one enum case + one binding, no
 * stringly-typed lookup. Distinct from VaulterManager::driver(), which resolves the
 * LEGACY Document-bound collaboration driver — this is the node-tree lane.
 */
final readonly class CollaborationDriverManager
{
    public function __construct(
        private Container $container,
        private DocumentsConfig $documentsConfig,
    ) {}

    /**
     * The node-collaboration driver for the configured (or given) transport.
     */
    public function driver(?string $name = null): CollaborationDriver
    {
        $type = $this->type($name ?? $this->documentsConfig->collaborationDriver());

        $class = match ($type) {
            CollaborationDriverType::Hocuspocus => HocuspocusCollaborationDriver::class,
        };

        return $this->container->make($class);
    }

    private function type(string $name): CollaborationDriverType
    {
        $type = CollaborationDriverType::tryFrom($name);

        if ($type === null) {
            throw new VaulterException(
                sprintf('[Vaulter] Unknown collaboration driver [%s]. ', $name)
                .'Fix: set config(vaulter-documents.collaboration.driver) to hocuspocus. '
                .'Docs: https://github.com/axiomasoft/vaulter/blob/main/docs/guides/errors.md#unknown-collaboration-driver',
            );
        }

        return $type;
    }
}
