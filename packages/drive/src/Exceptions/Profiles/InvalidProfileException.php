<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Profiles;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\VaulterException;

/**
 * Thrown at boot (ProfileRegistry::fromConfig / registerProvider) when a panel's
 * configuration is incoherent: a referenced FQCN (model, pipe, resource) does
 * not exist or does not satisfy its contract, or a panel declares a per-panel id
 * strategy while running on the shared default schema — a per-panel PK type
 * is meaningless when every panel shares the same v_* tables.
 *
 * Failing loud at boot (not at runtime) keeps a broken panel from silently
 * corrupting reads/writes once a request lands.
 */
#[Api]
final class InvalidProfileException extends VaulterException
{
    public static function missingClass(string $panel, string $kind, string $fqcn): self
    {
        return new self(
            "Storage panel [{$panel}] references a {$kind} class [{$fqcn}] that does not exist. "
            .'Fix: correct the FQCN in the panel provider.',
        );
    }

    public static function notInstanceOf(string $panel, string $kind, string $fqcn, string $contract): self
    {
        return new self(
            "Storage panel [{$panel}] {$kind} [{$fqcn}] must be a [{$contract}]. "
            .'Fix: implement/extend the expected contract in the referenced class.',
        );
    }

    public static function idStrategyWithoutOwnTables(string $panel): self
    {
        return new self(
            "Storage panel [{$panel}] sets idStrategy() but has no own tables. A per-panel id "
            .'strategy is incoherent on the shared v_* schema (one nodes table = one PK type). '
            .'Fix: either drop idStrategy() (use config(vaulter.ids.strategy)) or declare own tables '
            .'via tablePrefix()/tables() (advanced per-panel mode).',
        );
    }
}
