<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Modules;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A write of an active module ran before the module's schema was installed: no transaction was
 * opened. Retry after `php artisan migrate`.
 */
final class ModuleNotReadyException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    /** @param list<string> $missing */
    public static function missingTables(string $module, array $missing): self
    {
        return new self(sprintf(
            '[Vaulter] The module "%s" is not ready. Why: its table(s) %s do not exist. Fix: run php artisan migrate, then retry. Docs: %s',
            $module,
            implode(', ', array_slice($missing, 0, 5)),
            self::docs('module-not-ready'),
        ));
    }

    public static function inactive(string $module): self
    {
        return new self(sprintf(
            '[Vaulter] The module "%s" is not ready. Why: it is not active, so its operations cannot run. Fix: enable it in vaulter.modules (and its dependencies), then rebuild the caches. Docs: %s',
            $module,
            self::docs('module-not-ready'),
        ));
    }

    public function problemCode(): string
    {
        return 'module_not_ready';
    }

    public function httpStatus(): int
    {
        return 503;
    }
}
