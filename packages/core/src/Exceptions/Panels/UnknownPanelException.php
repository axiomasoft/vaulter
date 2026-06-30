<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Panels;

use Vaulter\Exceptions\VaulterException;

/**
 * Thrown when a storage panel is looked up by an id that is not registered in
 * the PanelRegistry (config('vaulter.panels') plus the default panel).
 */
final class UnknownPanelException extends VaulterException
{
    /**
     * @param list<string> $known
     */
    public static function forName(string $name, array $known): self
    {
        $available = $known === [] ? '(none)' : implode(', ', $known);

        return new self(
            "Unknown Vaulter storage panel [{$name}]. Registered: {$available}. "
            ."Fix: register a StoragePanelProvider FQCN under config('vaulter.panels').",
        );
    }
}
