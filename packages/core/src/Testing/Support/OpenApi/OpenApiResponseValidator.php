<?php

declare(strict_types=1);

namespace Vaulter\Testing\Support\OpenApi;

use LogicException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Packman\Attributes\Internal;
use stdClass;

/**
 * Judges one HTTP response against its operation: status, headers, media type and body.
 * Nothing undocumented passes — an unlisted status or media type is a violation, not a skip.
 * Bodies are checked by opis/json-schema (draft 2020-12), the dialect OpenAPI 3.1 uses.
 */
#[Internal]
final class OpenApiResponseValidator
{
    private readonly Validator $validator;

    public function __construct(private readonly OpenApiContract $contract)
    {
        $this->validator = new Validator;
        $resolver = $this->validator->resolver() ?? throw new LogicException('The JSON Schema validator has no resolver.');
        $resolver->registerRaw($contract->document, OpenApiContract::BASE_URI);
    }

    /**
     * @param  array<string, string> $headers lower-cased header name => value
     * @return list<string>          violations, empty when the response matches the contract
     */
    public function validate(string $operationId, int $status, array $headers, string $body, bool $inspectBody = true): array
    {
        $operation = $this->contract->operation($operationId);
        if ($operation === null) {
            return ["operation '{$operationId}' is not described in the OpenAPI document"];
        }

        $responses = $operation['operation']->responses ?? null;
        $key = (string) $status;
        if (!$responses instanceof stdClass || !property_exists($responses, $key)) {
            return ["{$operationId}: status {$status} is not documented"];
        }

        [$response, $pointer] = $this->contract->resolve(
            $responses->{$key},
            '/paths/'.OpenApiContract::escape($operation['path']).'/'.strtolower($operation['method']).'/responses/'.$key,
        );
        if (!$response instanceof stdClass) {
            return ["{$operationId}: the documented response for status {$status} is not an object"];
        }
        $violations = $this->headerViolations($operationId, $status, $response, $pointer, $headers);

        $content = $response->content ?? null;
        if (!$content instanceof stdClass || get_object_vars($content) === []) {
            if ($inspectBody && $body !== '') {
                $violations[] = "{$operationId}: status {$status} documents no content but the response has a body";
            }

            return $violations;
        }

        $actual = self::mediaType($headers['content-type'] ?? '');
        $mediaType = self::documentedMediaType($content, $actual);
        if ($mediaType === null) {
            $violations[] = "{$operationId}: status {$status} Content-Type '".($headers['content-type'] ?? '')."' is not documented";

            return $violations;
        }

        $entry = $content->{$mediaType};
        $schema = $entry instanceof stdClass ? ($entry->schema ?? null) : null;
        if (!$inspectBody || $schema === null || !str_contains($actual, 'json')) {
            return $violations;
        }

        $data = json_decode($body, false, 512, JSON_BIGINT_AS_STRING);
        if ($body === '' || json_last_error() !== JSON_ERROR_NONE) {
            $violations[] = "{$operationId}: status {$status} body is not valid JSON";

            return $violations;
        }

        if ($actual === 'application/problem+json' && is_object($data) && ($data->status ?? null) !== $status) {
            $violations[] = "{$operationId}: status {$status} problem body repeats status ".json_encode($data->status ?? null);
        }

        $schemaPointer = $pointer.'/content/'.OpenApiContract::escape($mediaType).'/schema';
        $result = $this->validator->validate($data, OpenApiContract::BASE_URI.'#'.OpenApiContract::fragment($schemaPointer));
        if (!$result->isValid()) {
            $error = $result->error();
            foreach ($error === null ? [] : (new ErrorFormatter)->formatKeyed($error) as $path => $messages) {
                $violations[] = "{$operationId}: status {$status} body {$path}: ".implode('; ', array_filter((array) $messages, is_string(...)));
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, string> $headers
     * @return list<string>
     */
    private function headerViolations(string $operationId, int $status, stdClass $response, string $responsePointer, array $headers): array
    {
        $violations = [];
        $declared = $response->headers ?? null;
        foreach ($declared instanceof stdClass ? get_object_vars($declared) : [] as $name => $definition) {
            [$definition, $pointer] = $this->contract->resolve($definition, $responsePointer.'/headers/'.OpenApiContract::escape((string) $name));
            if (!$definition instanceof stdClass) {
                continue;
            }
            $value = $headers[strtolower((string) $name)] ?? null;
            if ($value === null) {
                if (($definition->required ?? false) === true) {
                    $violations[] = "{$operationId}: status {$status} is missing the required header {$name}";
                }

                continue;
            }

            $schema = $definition->schema ?? null;
            if ($schema === null) {
                continue;
            }
            $result = $this->validator->validate(
                $this->headerValue($value, $schema),
                OpenApiContract::BASE_URI.'#'.OpenApiContract::fragment($pointer.'/schema'),
            );
            if (!$result->isValid()) {
                $violations[] = "{$operationId}: status {$status} header {$name}: '{$value}' does not match its schema";
            }
        }

        return $violations;
    }

    private function headerValue(string $value, mixed $schema): string|int
    {
        [$schema] = $this->contract->resolve($schema, '');

        return $schema instanceof stdClass && ($schema->type ?? null) === 'integer' && ctype_digit($value) ? (int) $value : $value;
    }

    /**
     * The documented key a response media type falls under: exact, then `type/*`, then `*` slash `*`.
     */
    private static function documentedMediaType(stdClass $content, string $actual): ?string
    {
        if ($actual === '') {
            return null;
        }

        foreach ([$actual, explode('/', $actual)[0].'/*', '*/*'] as $candidate) {
            if (property_exists($content, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function mediaType(string $contentType): string
    {
        return strtolower(trim(explode(';', $contentType)[0]));
    }
}
