<?php

declare(strict_types=1);

namespace Vaulter\Exceptions;

final class UnknownExportFormatException extends VaulterException
{
    /**
     * @param list<string> $allowed
     */
    public static function forFormat(string $format, array $allowed): self
    {
        $list = implode(', ', $allowed);

        return new self(
            sprintf('[Vaulter] Unknown export format [%s]. ', $format)
            .sprintf('Why: no exporter is registered for it (available: [%s]). ', $list)
            .'Fix: register one via config(vaulter.exporters) or Vaulter::registerExporter(). '
            .'Docs: '.self::docs('unknown-export-format'),
        );
    }
}
