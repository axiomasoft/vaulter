<?php

declare(strict_types=1);

namespace Vaulter\Documents\Export;

use Closure;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Documents\Contracts\DocumentExporter;
use Vaulter\Documents\Exceptions\DuplicateDocumentExporterException;
use Vaulter\Documents\Exceptions\UnknownExportFormatException;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Exceptions\Registry\RegistryFrozenException;

/**
 * The document exporters of this installation, resolved by (document type, format).
 *
 * Like the type registry it is filled while providers boot and frozen afterwards.
 * Two exporters for one pair are an error whatever the provider order; a host swaps
 * an exporter only through replace().
 */
#[Api]
final class DocumentExporterRegistry
{
    private const string KEY_PATTERN = '/^[a-z0-9-]+\/[a-z0-9-]+$/';

    /** @var array<string, DocumentExporter> */
    private array $exporters = [];

    /** @var array<string, DocumentExporter|string> Boot declarations, including host-provided instances. */
    private array $definitions = [];

    private bool $frozen = false;

    #[Internal]
    public function __construct(private readonly Container $container) {}

    /**
     * @param DocumentExporter|class-string<DocumentExporter>|string $exporter
     *
     * @throws DuplicateDocumentExporterException when the key or a (type, format) pair is taken
     */
    public function register(DocumentExporter|string $exporter): void
    {
        $this->assertOpen();
        $instance = $this->instance($exporter);

        if (isset($this->exporters[$instance->key()])) {
            throw DuplicateDocumentExporterException::forKey($instance->key());
        }
        $this->assertNoPairCollision($instance, except: null);

        $this->exporters[$instance->key()] = $instance;
        $this->definitions[$instance->key()] = $exporter;
    }

    /**
     * Swap the implementation behind an existing key.
     *
     * @param DocumentExporter|class-string<DocumentExporter>|string $exporter
     */
    public function replace(string $key, DocumentExporter|string $exporter): void
    {
        $this->assertOpen();
        $instance = $this->instance($exporter);

        if ($instance->key() !== $key) {
            throw new InvalidConfigurationException(sprintf('[Vaulter] Document exporter %s has key "%s", not "%s".', $instance::class, $instance->key(), $key));
        }
        if (!isset($this->exporters[$key])) {
            throw new InvalidConfigurationException(sprintf('[Vaulter] Document exporter "%s" is not registered, so it cannot be replaced.', $key));
        }
        $this->assertNoPairCollision($instance, except: $key);

        $this->exporters[$key] = $instance;
        $this->definitions[$key] = $exporter;
    }

    /** @throws UnknownExportFormatException when no exporter renders $format for the type */
    public function for(string $typeKey, string $format): DocumentExporter
    {
        foreach ($this->exporters as $exporter) {
            if ($exporter->format() === $format && in_array($typeKey, $exporter->documentTypes(), true)) {
                return $exporter;
            }
        }

        throw UnknownExportFormatException::forFormat($format, $this->formatsFor($typeKey));
    }

    /**
     * The formats a document of $typeKey can be exported in.
     *
     * @return list<string>
     */
    public function formatsFor(string $typeKey): array
    {
        $formats = [];
        foreach ($this->exporters as $exporter) {
            if (in_array($typeKey, $exporter->documentTypes(), true)) {
                $formats[] = $exporter->format();
            }
        }
        sort($formats, SORT_STRING);

        return array_values(array_unique($formats));
    }

    /**
     * Ordered by key, so the result never depends on the order providers ran in.
     *
     * @return array<string, DocumentExporter>
     */
    public function all(): array
    {
        $exporters = $this->exporters;
        ksort($exporters, SORT_STRING);

        return $exporters;
    }

    /** Called once the application has booted. */
    #[Internal]
    public function freeze(): void
    {
        $this->frozen = true;
    }

    /**
     * @param  list<DocumentExporter>   $tagged
     * @return Closure(Container): self
     */
    #[Internal]
    public function factoryForWorkerScopes(array $tagged): Closure
    {
        $definitions = $this->definitions;

        $tagKeys = [];
        foreach ($tagged as $extension) {
            $key = $extension->key();
            if (($definitions[$key] ?? null) === $extension) {
                unset($definitions[$key]);
                $tagKeys[$key] = true;
            }
        }

        return static function (Container $scope) use ($definitions, $tagKeys): self {
            $registry = new self($scope);
            foreach ($definitions as $definition) {
                $registry->register($definition);
            }
            if ($tagKeys !== []) {
                foreach ($scope->tagged('vaulter.exporters') as $extension) {
                    if ($extension instanceof DocumentExporter && isset($tagKeys[$extension->key()])) {
                        $registry->register($extension);
                    }
                }
            }
            $registry->freeze();

            return $registry;
        };
    }

    #[Internal]
    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    private function assertNoPairCollision(DocumentExporter $incoming, ?string $except): void
    {
        foreach ($this->exporters as $key => $existing) {
            if ($key === $except || $existing->format() !== $incoming->format()) {
                continue;
            }
            foreach ($incoming->documentTypes() as $type) {
                if (in_array($type, $existing->documentTypes(), true)) {
                    throw DuplicateDocumentExporterException::forPair($type, $incoming->format(), $key, $incoming->key());
                }
            }
        }
    }

    private function assertOpen(): void
    {
        if ($this->frozen) {
            throw RegistryFrozenException::forRegistry('document exporter');
        }
    }

    private function instance(DocumentExporter|string $exporter): DocumentExporter
    {
        if (is_string($exporter)) {
            if (!class_exists($exporter) || !is_subclass_of($exporter, DocumentExporter::class)) {
                throw new InvalidConfigurationException(sprintf('[Vaulter] Document exporter "%s" is not a class implementing %s.', $exporter, DocumentExporter::class));
            }
            $resolved = $this->container->make($exporter);
            if (!$resolved instanceof DocumentExporter) {
                throw new InvalidConfigurationException(sprintf('[Vaulter] Document exporter "%s" did not resolve to a %s.', get_debug_type($resolved), DocumentExporter::class));
            }
            $exporter = $resolved;
        }

        if (preg_match(self::KEY_PATTERN, $exporter->key()) !== 1) {
            throw new InvalidConfigurationException(sprintf('[Vaulter] Document exporter key "%s" of %s must look like vendor/name ([a-z0-9-]).', $exporter->key(), $exporter::class));
        }
        if (preg_match('/^[a-z0-9][a-z0-9.-]*$/', $exporter->format()) !== 1) {
            throw new InvalidConfigurationException(sprintf('[Vaulter] Document exporter "%s" has format "%s"; it becomes a URL segment, so use [a-z0-9.-].', $exporter->key(), $exporter->format()));
        }

        return $exporter;
    }
}
