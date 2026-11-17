<?php

declare(strict_types=1);

namespace Vaulter\Documents;

use Packman\Attributes\Api;
use Vaulter\Documents\Export\DocumentExporterRegistry;
use Vaulter\Documents\Operations\CommentOperations;
use Vaulter\Documents\Operations\DocumentOperations;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Drive\Contracts\DriveHandle;

#[Api]
final readonly class DocumentsManager
{
    public function __construct(
        private DocumentTypeRegistry $types,
        private DocumentExporterRegistry $exporters,
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

    /** The comments on the nodes of one drive, as the handle's actor. */
    public function comments(DriveHandle $drive): CommentOperations
    {
        return $drive->extension(CommentOperations::class);
    }
}
