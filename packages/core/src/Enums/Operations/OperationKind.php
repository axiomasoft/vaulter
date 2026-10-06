<?php

declare(strict_types=1);

namespace Vaulter\Enums\Operations;

/** Public operation categories; private scanner authority is a separate service. */
enum OperationKind: string
{
    case Metadata = 'metadata';
    case Mutation = 'mutation';
    case ContentEgress = 'content_egress';
    case Purge = 'purge';
}
