<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Cas;

use DateTimeImmutable;
use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Content\ImmutableContentIdentity;
use Vaulter\Content\SignedContentUrl;

/** Trusted adapters sign an exact version or guarantee write-once immutable keys. */
#[Spi(SpiKind::Implement)]
interface DirectContentUrlSigner
{
    public function sign(ImmutableContentIdentity $content, DateTimeImmutable $expiresAt): SignedContentUrl;
}
