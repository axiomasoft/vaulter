<?php

declare(strict_types=1);

namespace Vaulter\Testing\Support\OpenApi;

use LogicException;
use Packman\Attributes\Internal;
use stdClass;
use Symfony\Component\Yaml\Yaml;

/**
 * The OpenAPI 3.1 document as the contract tests read it: operations indexed by operationId,
 * `$ref` resolution inside the file, and a hard stop on any construct the response validator
 * cannot judge. A silent skip would turn a typo in the spec into a green test.
 */
#[Internal]
final class OpenApiContract
{
    public const string BASE_URI = 'https://vaulter.test/openapi';

    private const array METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /** @var array<string, string> */
    private const array UNSUPPORTED_KEYWORDS = [
        'discriminator' => 'discriminator',
        'callbacks' => 'callbacks',
        'webhooks' => 'webhooks',
        '$dynamicRef' => '$dynamicRef',
        '$recursiveRef' => '$recursiveRef',
    ];

    private static ?self $shared = null;

    /** @var array<string, array{method: string, path: string, operation: stdClass}> */
    private array $operations = [];

    private function __construct(public readonly stdClass $document)
    {
        $this->assertSupported($document, '#');
        $this->index();
    }

    public static function specPath(): string
    {
        return dirname(__DIR__, 6).'/openapi/vaulter-v1.yaml';
    }

    public static function shared(): self
    {
        return self::$shared ??= self::fromFile(self::specPath());
    }

    public static function fromFile(string $path): self
    {
        $yaml = file_get_contents($path);
        if ($yaml === false) {
            throw new LogicException("OpenAPI document {$path} is not readable.");
        }

        return self::fromYaml($yaml);
    }

    public static function fromYaml(string $yaml): self
    {
        $document = Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP);
        if (!$document instanceof stdClass) {
            throw new LogicException('The OpenAPI document must be a mapping.');
        }

        return new self($document);
    }

    /**
     * @return array<string, array{method: string, path: string, operation: stdClass}>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * @return array{method: string, path: string, operation: stdClass}|null
     */
    public function operation(string $operationId): ?array
    {
        return $this->operations[$operationId] ?? null;
    }

    /**
     * The JSON pointer of a `$ref`, relative to the document root.
     */
    public function pointerOf(string $ref): string
    {
        if (!str_starts_with($ref, '#/')) {
            throw new UnsupportedOpenApiConstruct("External or non-pointer \$ref '{$ref}' is not supported.");
        }

        return substr($ref, 1);
    }

    /**
     * Follows `$ref` chains until a concrete node; returns it with the pointer it lives at.
     *
     * @return array{0: mixed, 1: string}
     */
    public function resolve(mixed $node, string $pointer): array
    {
        $seen = [];
        while ($node instanceof stdClass && isset($node->{'$ref'})) {
            $ref = $node->{'$ref'};
            $pointer = $this->pointerOf(is_string($ref) ? $ref : throw new LogicException('A $ref must be a string, found '.get_debug_type($ref).' at '.$pointer.'.'));
            if (isset($seen[$pointer])) {
                throw new LogicException("Circular \$ref at {$pointer}.");
            }
            $seen[$pointer] = true;
            $node = $this->at($pointer);
        }

        return [$node, $pointer];
    }

    public function at(string $pointer): mixed
    {
        $node = $this->document;
        foreach (array_slice(explode('/', $pointer), 1) as $segment) {
            $key = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));
            if ($node instanceof stdClass && property_exists($node, $key)) {
                $node = $node->{$key};
            } elseif (is_array($node) && array_key_exists((int) $key, $node)) {
                $node = $node[(int) $key];
            } else {
                throw new LogicException("Unresolvable \$ref pointer {$pointer}.");
            }
        }

        return $node;
    }

    /**
     * A JSON pointer as a URI fragment: every segment percent-encoded, so `{node}` survives the URI parser.
     */
    public static function fragment(string $pointer): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $pointer)));
    }

    public static function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }

    /**
     * Every `$ref` found in the document, as [location, target] pairs.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function references(): array
    {
        $found = [];
        $this->collectReferences($this->document, '#', $found);

        return $found;
    }

    /**
     * @param list<array{0: string, 1: string}> $found
     */
    private function collectReferences(mixed $node, string $location, array &$found): void
    {
        if ($node instanceof stdClass) {
            foreach (get_object_vars($node) as $key => $value) {
                if ($key === '$ref' && is_string($value)) {
                    $found[] = [$location, $value];

                    continue;
                }
                $this->collectReferences($value, $location.'/'.self::escape((string) $key), $found);
            }
        } elseif (is_array($node)) {
            foreach ($node as $index => $value) {
                $this->collectReferences($value, $location.'/'.$index, $found);
            }
        }
    }

    private function assertSupported(mixed $node, string $location): void
    {
        if ($node instanceof stdClass) {
            foreach (get_object_vars($node) as $key => $value) {
                $key = (string) $key;
                if (isset(self::UNSUPPORTED_KEYWORDS[$key]) && !$this->isPropertyName($location)) {
                    throw new UnsupportedOpenApiConstruct("'{$key}' at {$location} is not supported by the response validator.");
                }
                if ($key === '$ref') {
                    $this->pointerOf(is_string($value) ? $value : throw new UnsupportedOpenApiConstruct("A \$ref at {$location} must be a string."));
                }
                $this->assertSupported($value, $location.'/'.self::escape($key));
            }
        } elseif (is_array($node)) {
            foreach ($node as $index => $value) {
                $this->assertSupported($value, $location.'/'.$index);
            }
        }
    }

    /** A schema property may legitimately be called "callbacks"; only keywords are rejected. */
    private function isPropertyName(string $location): bool
    {
        return str_ends_with($location, '/properties');
    }

    private function index(): void
    {
        $paths = $this->document->paths ?? null;
        foreach ($paths instanceof stdClass ? get_object_vars($paths) : [] as $path => $item) {
            if (!$item instanceof stdClass) {
                continue;
            }
            foreach (get_object_vars($item) as $method => $operation) {
                if (!in_array($method, self::METHODS, true)) {
                    continue;
                }
                $id = $operation instanceof stdClass ? ($operation->operationId ?? null) : null;
                if (!$operation instanceof stdClass || !is_string($id) || $id === '') {
                    throw new LogicException("Operation {$method} {$path} has no operationId.");
                }
                if (isset($this->operations[$id])) {
                    throw new LogicException("Duplicate operationId '{$id}'.");
                }
                $this->operations[$id] = ['method' => strtoupper($method), 'path' => (string) $path, 'operation' => $operation];
            }
        }
    }
}
