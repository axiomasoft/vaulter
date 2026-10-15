<?php

declare(strict_types=1);

namespace Vaulter\Content;

use DateTimeImmutable;
use InvalidArgumentException;
use Vaulter\Attributes\Api;

#[Api]
final readonly class SignedContentUrl
{
    public function __construct(public string $url, public DateTimeImmutable $expiresAt, public string $contentIdentity, public ?string $storageVersion)
    {
        if ($url === '' || preg_match('/^[a-f0-9]{64}$/D', $contentIdentity) !== 1 || !in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)) {
            throw new InvalidArgumentException('Invalid signed content capability.');
        }
    }
}
