<?php

declare(strict_types=1);

namespace Vaulter\Documents;

use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Api;
use Vaulter\Documents\Export\DocumentExporterRegistry;
use Vaulter\Documents\Operations\CommentOperations;
use Vaulter\Documents\Operations\DocumentOperations;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Operations\DriveContext;

#[Api]
final readonly class DocumentsManager
{
    public function __construct(
        private DocumentTypeRegistry $types,
        private DocumentExporterRegistry $exporters,
        private Container $container,
    ) {}

    public function types(): DocumentTypeRegistry
    {
        return $this->types;
    }

    public function exporters(): DocumentExporterRegistry
    {
        return $this->exporters;
    }

    /**
     * The documents of one drive, as the handle's actor. Each call gets fresh
     * operations bound to the handle's current actor and correlation id.
     */
    public function in(DriveHandle $drive): DocumentOperations
    {
        return $drive->extension(DocumentOperations::class);
    }

    /** Use an already resolved drive operation context. */
    public function forContext(DriveContext $context): DocumentOperations
    {
        return $this->container->makeWith(DocumentOperations::class, ['context' => $context]);
    }

    /** The comments on the nodes of one drive, as the handle's actor. */
    public function comments(DriveHandle $drive): CommentOperations
    {
        return $drive->extension(CommentOperations::class);
    }
}
