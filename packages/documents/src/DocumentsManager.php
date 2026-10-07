<?php

declare(strict_types=1);

namespace Vaulter\Documents;

use Vaulter\Documents\Registries\DocumentExporterRegistry;
use Vaulter\Documents\Registries\DocumentTypeRegistry;

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
}
