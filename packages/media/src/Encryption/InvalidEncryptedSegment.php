<?php

declare(strict_types=1);

namespace Vaulter\Media\Encryption;

use Packman\Attributes\Internal;
use RuntimeException;

#[Internal]
final class InvalidEncryptedSegment extends RuntimeException {}
