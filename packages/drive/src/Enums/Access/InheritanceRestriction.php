<?php

declare(strict_types=1);

namespace Vaulter\Drive\Enums\Access;

use Vaulter\Attributes\Api;

/**
 * How access()->restrictInheritance() closes a node to grants from above.
 *
 *   empty         only the node's own grants (and those below it) apply from now on;
 *   copy_current  the roles currently inherited are first copied onto the node as
 *                 direct grants of the same subjects, never broader or longer-lived.
 *
 * @closed
 */
#[Api]
enum InheritanceRestriction: string
{
    case Empty = 'empty';
    case CopyCurrent = 'copy_current';
}
