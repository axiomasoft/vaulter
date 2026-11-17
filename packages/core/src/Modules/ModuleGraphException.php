<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Errors\VaulterErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * The installed modules do not form a valid graph, so no module is active: the reasons are printed by
 * `vaulter:modules` and `vaulter:doctor`.
 */
#[Api]
final class ModuleGraphException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: VaulterErrorCode::ModuleGraphInvalid, category: ErrorCategory::Unavailable);
    }

    /** @param list<string> $reasons */
    public static function invalid(array $reasons): self
    {
        return new self(
            '[Vaulter] The module graph is invalid, no module is active. '
            .'Why: '.implode('; ', $reasons).'. '
            .'Fix: correct the manifests or vaulter.modules, then rebuild the caches and restart the workers. '
            .'Docs: '.self::docs('module-graph-invalid'),
        );
    }

    public static function notCompiled(): self
    {
        return new self(
            '[Vaulter] The modules are not compiled yet. '
            .'Why: capabilities and slots are read before the application booted. '
            .'Fix: read them after boot, never from a service provider\'s register(). '
            .'Docs: '.self::docs('module-graph-invalid'),
        );
    }

    public function problemCode(): string
    {
        return 'module_graph_invalid';
    }

    public function httpStatus(): int
    {
        return 503;
    }
}
