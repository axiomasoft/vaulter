<?php

declare(strict_types=1);

namespace DocVault\Exceptions;

final class UnknownExportFormatException extends DocVaultException
{
    /**
     * @param list<string> $allowed
     */
    public static function forFormat(string $format, array $allowed): self
    {
        $list = implode(', ', $allowed);

        return new self(
            sprintf('[DocVault] Unknown export format [%s]. ', $format)
            .sprintf('Why: no exporter is registered for it (available: [%s]). ', $list)
            .'Fix: register one via config(docvault.exporters) or DocVault::registerExporter(). '
            .'Docs: '.self::docs('unknown-export-format'),
        );
    }
}
