<?php

declare(strict_types=1);

namespace Vaulter\Media\Encryption;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SensitiveParameter;

/** Bind the host KMS adapter to opt new CAS objects into encryption. Keep historical keys available for unwrap. */
#[Spi(SpiKind::Implement)]
interface KeyManagementPort
{
    /** @return array{key_id:int|string, wrapped_dek:string} A database-compatible key ID and binary KMS envelope. */
    public function wrap(#[SensitiveParameter] string $dek): array;

    public function unwrap(int|string $keyId, string $wrappedDek): string;
}
