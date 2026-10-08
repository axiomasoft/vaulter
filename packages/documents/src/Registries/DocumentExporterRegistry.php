<?php

declare(strict_types=1);

namespace Vaulter\Documents\Registries;

use Illuminate\Contracts\Container\Container;
use Vaulter\Contracts\Handlers\DocumentExporter;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Exceptions\UnknownExportFormatException;

final class DocumentExporterRegistry
{
    /** @var array<string, DocumentExporter> */
    private array $exporters = [];

    public function __construct(
        private readonly Container $container,
        private readonly DocumentsConfig $config,
    ) {}

    /** @param DocumentExporter|class-string<DocumentExporter> $exporter */
    public function register(DocumentExporter|string $exporter): void
    {
        /** @var DocumentExporter $instance */
        $instance = $exporter instanceof DocumentExporter ? $exporter : $this->container->make($exporter);
        $this->exporters[$instance->format()] = $instance;
    }

    public function get(string $format): DocumentExporter
    {
        if (isset($this->exporters[$format])) {
            return $this->exporters[$format];
        }

        $configured = $this->config->exporters();
        if (isset($configured[$format])) {
            /** @var DocumentExporter $exporter */
            $exporter = $this->container->make($configured[$format]);

            return $this->exporters[$format] = $exporter;
        }

        throw UnknownExportFormatException::forFormat($format, $this->formats());
    }

    /** @return list<string> */
    public function formats(): array
    {
        return array_values(array_unique([
            ...array_keys($this->config->exporters()),
            ...array_keys($this->exporters),
        ]));
    }
}
