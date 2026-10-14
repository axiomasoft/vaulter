<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use InvalidArgumentException;
use Vaulter\Modules\ModuleId;

/**
 * What an operation is: its stable name (the operation identity, as an OperationKind or route
 * label: `drive.folders.create`), the module that owns it and whether it writes.
 */
final readonly class OperationSpec
{
    public const string NAME_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    public function __construct(
        public string $name,
        public ModuleId $module,
        public bool $mutating = true,
    ) {
        if (strlen($name) > 64 || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf('[Vaulter] "%s" is not an operation name: expected dotted lowercase segments of at most 64 bytes.', $name));
        }
    }
}
