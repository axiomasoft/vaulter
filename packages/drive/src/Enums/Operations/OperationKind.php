<?php

declare(strict_types=1);

namespace Vaulter\Drive\Enums\Operations;

use Packman\Attributes\Api;

/** Public operation categories; private scanner authority is a separate service. */
#[Api]
enum OperationKind: string
{
    case Metadata = 'metadata';
    case Mutation = 'mutation';
    case ContentEgress = 'content_egress';
    case Purge = 'purge';
}
