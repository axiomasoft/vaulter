<?php

declare(strict_types=1);

namespace Vaulter\Jobs;

use Vaulter\Attributes\Api;

#[Api]
final readonly class PublicationContext
{
    /** @param array<string,string> $refs */
    public function __construct(public string $type, public int $version, public array $refs)
    {
        if (!preg_match('/^[a-z][a-z0-9.-]{0,63}:[a-z][a-z0-9.-]{0,63}$/D', $type) || $version < 1) {
            throw new \InvalidArgumentException('Invalid publication context.');
        }
        ScalarFields::validate($refs, 32768);
    }
}
