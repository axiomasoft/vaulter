<?php

declare(strict_types=1);

namespace Vaulter\Media\Content;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api]
final readonly class SignedContentUrl
{
    public function __construct(public string $url, public DateTimeImmutable $expiresAt, public string $contentIdentity, public ?string $storageVersion)
    {
        if ($url === '' || preg_match('/^[a-f0-9]{64}$/D', $contentIdentity) !== 1 || preg_match('~\Ahttps?://[^/\s?#]+~', $url) !== 1) {
            throw new InvalidArgumentException('Invalid signed content capability.');
        }
    }
}
