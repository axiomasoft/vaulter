<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Nodes;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A node name is a single path segment: non-empty after trimming, free of separators
 * and control characters, not `.` or `..`, and bounded in UTF-8 bytes. Rendered as a
 * validation error on `name` so API clients handle it like a form-request failure.
 */
final class InvalidNodeNameException extends VaulterException implements ProblemException
{
    private const string PROBLEM_CODE = 'validation_failed';

    private const int HTTP_STATUS = 422;

    public static function forName(string $name, string $reason): self
    {
        $printable = json_encode($name, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return new self(
            "[Vaulter] The node name {$printable} is invalid: {$reason}. "
            .'Fix: use a single non-empty segment without separators or control characters. '
            .'Docs: '.self::docs('invalid-node-name'),
        );
    }

    public function problemCode(): string
    {
        return self::PROBLEM_CODE;
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function renderExtras(): array
    {
        return ['errors' => ['name' => [$this->getMessage()]]];
    }
}
