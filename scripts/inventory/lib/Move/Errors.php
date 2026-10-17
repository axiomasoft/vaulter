<?php

declare(strict_types=1);

namespace Vaulter\Inventory\Move;

use RuntimeException;

/** A usage error, a conflict, an empty selection or a replace/split type: nothing is changed (CLI exit 2). */
final class MoveUsage extends RuntimeException {}

/** A failure while reading or applying (an unparsable file, an unwritable path): CLI exit 1. */
final class MoveFailure extends RuntimeException {}
