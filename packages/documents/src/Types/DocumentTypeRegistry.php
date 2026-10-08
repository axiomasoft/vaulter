<?php

declare(strict_types=1);

namespace Vaulter\Documents\Types;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Exceptions\DuplicateDocumentTypeException;
use Vaulter\Documents\Exceptions\UnknownDocumentTypeException;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Exceptions\Registry\RegistryFrozenException;

/**
 * The document types of this installation, by `vendor/name` key.
 *
 * Types come from config `vaulter-documents.types`, the container tag
 * `vaulter.document_types` and register() calls of service providers; once the
 * application has booted the registry is frozen, so every request and every Octane
 * worker sees one immutable set. Registering a key twice is an error whatever the
 * provider order; a host overrides a type only through replace(). The `vaulter/`
 * prefix belongs to the package's own types.
 *
 * A document whose type is not registered (an extension was removed) stays readable:
 * find() returns null and the operations fall back to {@see UnknownDocumentType}.
 */
final class DocumentTypeRegistry
{
    private const string KEY_PATTERN = '/^[a-z0-9-]+\/[a-z0-9-]+$/';

    /** @var array<string, DocumentType> */
    private array $types = [];

    private bool $frozen = false;

    public function __construct(private readonly Container $container) {}

    /**
     * @param DocumentType|class-string<DocumentType>|string $type
     *
     * @throws DuplicateDocumentTypeException when the key is already registered
     */
    public function register(DocumentType|string $type): void
    {
        $this->assertOpen();
        $instance = $this->instance($type);
        $key = $instance->key();

        if (isset($this->types[$key])) {
            throw DuplicateDocumentTypeException::forKey($key);
        }

        $this->types[$key] = $instance;
    }

    /**
     * Swap the implementation behind an existing key (a host's own rich-text type, say).
     *
     * @param DocumentType|class-string<DocumentType>|string $type
     */
    public function replace(string $key, DocumentType|string $type): void
    {
        $this->assertOpen();
        $instance = $this->instance($type);

        if ($instance->key() !== $key) {
            throw new InvalidConfigurationException(sprintf('[Vaulter] Document type %s has key "%s", not "%s".', $instance::class, $instance->key(), $key));
        }
        if (!isset($this->types[$key])) {
            throw UnknownDocumentTypeException::forType($key, array_keys($this->all()));
        }

        $this->types[$key] = $instance;
        Log::info('[Vaulter] Document type replaced.', ['key' => $key, 'type' => $instance::class]);
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    /** @throws UnknownDocumentTypeException */
    public function get(string $key): DocumentType
    {
        return $this->types[$key] ?? throw UnknownDocumentTypeException::forType($key, array_keys($this->all()));
    }

    /** Null for a key nothing registers (any more). */
    public function find(string $key): ?DocumentType
    {
        return $this->types[$key] ?? null;
    }

    /**
     * Ordered by key, so the result never depends on the order providers ran in.
     *
     * @return array<string, DocumentType>
     */
    public function all(): array
    {
        $types = $this->types;
        ksort($types, SORT_STRING);

        return $types;
    }

    /** @internal Called once the application has booted. */
    public function freeze(): void
    {
        $this->frozen = true;
    }

    /** @internal */
    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /** A class shipped by a Vaulter package (not a test double, not an anonymous class). */
    private static function firstParty(string $class): bool
    {
        return str_starts_with($class, 'Vaulter\\') && !str_contains($class, '\\Tests\\') && !str_contains($class, '@anonymous');
    }

    private function assertOpen(): void
    {
        if ($this->frozen) {
            throw RegistryFrozenException::forRegistry('document type');
        }
    }

    private function instance(DocumentType|string $type): DocumentType
    {
        if (is_string($type)) {
            if (!class_exists($type) || !is_subclass_of($type, DocumentType::class)) {
                throw new InvalidConfigurationException(sprintf('[Vaulter] Document type "%s" is not a class implementing %s.', $type, DocumentType::class));
            }
            $resolved = $this->container->make($type);
            if (!$resolved instanceof DocumentType) {
                throw new InvalidConfigurationException(sprintf('[Vaulter] Document type "%s" did not resolve to a %s.', get_debug_type($resolved), DocumentType::class));
            }
            $type = $resolved;
        }

        $key = $type->key();
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidConfigurationException(sprintf('[Vaulter] Document type key "%s" of %s must look like vendor/name ([a-z0-9-]).', $key, $type::class));
        }
        if (str_starts_with($key, 'vaulter/') && !self::firstParty($type::class)) {
            throw new InvalidConfigurationException(sprintf('[Vaulter] Document type key "%s" of %s uses the reserved vaulter/ prefix; use your own vendor.', $key, $type::class));
        }
        if ($type->schemaVersion() < 1) {
            throw new InvalidConfigurationException(sprintf('[Vaulter] Document type "%s" must have a schema version of at least 1.', $key));
        }

        return $type;
    }
}
