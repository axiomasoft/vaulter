<?php

declare(strict_types=1);

namespace Vaulter\Media\Contracts\Cas;

use DateTimeImmutable;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Vaulter\Media\Content\ImmutableContentIdentity;
use Vaulter\Media\Content\SignedContentUrl;

/** Trusted adapters sign an exact version or guarantee write-once immutable keys. */
#[Spi(SpiKind::Implement)]
interface DirectContentUrlSigner
{
    public function sign(ImmutableContentIdentity $content, DateTimeImmutable $expiresAt): SignedContentUrl;
}
