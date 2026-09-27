<?php

declare(strict_types=1);

namespace Vaulter\Testing;

use RuntimeException;
use Throwable;
use Vaulter\Contracts\Observability\TraceRecorder;

/**
 * In-memory {@see TraceRecorder} for package tests (P8.9). Records span nesting,
 * attributes and error status without a tracing backend.
 */
final class FakeTraceRecorder implements TraceRecorder
{
    /** @var list<string> */
    private array $stack = [];

    /** @var list<array{name: string, parent: ?string, attributes: array<string, scalar>, status: string}> */
    private array $spans = [];

    /** @param array<string, scalar> $attributes */
    public function span(string $name, callable $operation, array $attributes = []): mixed
    {
        $parent = $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
        $this->stack[] = $name;
        $status = 'unset';

        try {
            return $operation();
        } catch (Throwable $exception) {
            $status = 'error';
            $attributes = array_merge($attributes, [
                'vaulter.outcome' => 'internal_error',
            ]);

            throw $exception;
        } finally {
            array_pop($this->stack);

            $this->spans[] = [
                'name' => $name,
                'parent' => $parent,
                'attributes' => $attributes,
                'status' => $status,
            ];
        }
    }

    /**
     * @return list<array{name: string, parent: ?string, attributes: array<string, scalar>, status: string}>
     */
    public function spans(): array
    {
        return $this->spans;
    }

    /** @param array<string, scalar>|null $attributes */
    public function assertSpan(string $name, ?array $attributes = null, ?string $parent = null): void
    {
        foreach ($this->spans as $span) {
            if ($span['name'] !== $name) {
                continue;
            }

            if ($parent !== null && $span['parent'] !== $parent) {
                continue;
            }

            if ($attributes !== null && $span['attributes'] !== $attributes) {
                continue;
            }

            return;
        }

        throw new RuntimeException(sprintf(
            'Expected span %s%s%s',
            $name,
            $parent === null ? '' : ' with parent '.$parent,
            $attributes === null ? '' : ' with attributes '.json_encode($attributes, JSON_THROW_ON_ERROR),
        ));
    }
}
