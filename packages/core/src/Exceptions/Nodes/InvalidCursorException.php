<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Nodes;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

final class InvalidCursorException extends VaulterException implements ProblemException
{
    public static function invalid(): self
    {
        return new self('[Vaulter] Invalid listing cursor. Fix: restart the listing without a cursor. Docs: '.self::docs('invalid-cursor'));
    }

    public function problemCode(): string
    {
        return 'validation_failed';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    /** @return array<string, mixed> */
    protected function renderExtras(): array
    {
        return ['errors' => ['cursor' => [$this->getMessage()]]];
    }
}
