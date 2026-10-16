<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Registry;

use Vaulter\Attributes\Internal;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/** Raised while the application boots, so it is never rendered for a request. */
#[Internal]
final class DuplicateNodeContentLifecycleException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function forKind(NodeKind $kind): self
    {
        return new self('[Vaulter] Duplicate node content lifecycle for kind "'.$kind->value.'".');
    }

    public function problemCode(): string
    {
        return 'duplicate_registration';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
