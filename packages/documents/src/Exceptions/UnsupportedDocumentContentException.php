<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Exceptions\VaulterException;

final class UnsupportedDocumentContentException extends VaulterException
{
    public static function forExporter(string $exporterFormat, string $expected, string $actual): self
    {
        return new self(
            sprintf('[Vaulter] The [%s] exporter cannot render this content. ', $exporterFormat)
            .sprintf('Why: it only understands [%s], but received [%s]. ', $expected, $actual)
            .'Fix: only route nodes whose resolved handler produces the expected content shape to '
            .'this exporter (config(vaulter.exporters)). '
            .'Docs: '.self::docs('unsupported-document-content'),
        );
    }
}
