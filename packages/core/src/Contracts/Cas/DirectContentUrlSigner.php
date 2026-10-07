<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Cas;

use DateTimeImmutable;
use Vaulter\Content\ImmutableContentIdentity;
use Vaulter\Content\SignedContentUrl;

/** Trusted adapters sign an exact version or guarantee write-once immutable keys. */
interface DirectContentUrlSigner
{
    public function sign(ImmutableContentIdentity $content, DateTimeImmutable $expiresAt): SignedContentUrl;
}
