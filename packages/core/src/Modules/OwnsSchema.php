<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Vaulter\Attributes\Internal;
use Vaulter\Schema\ModuleSchema;

/**
 * A module manifest whose module owns tables: it names the schema class that holds their DDL and entity list. Static,
 * so the kernel reads it from the compiled manifest class without constructing the manifest.
 */
#[Internal]
interface OwnsSchema
{
    /** @return class-string<ModuleSchema> */
    public static function ownedSchema(): string;
}
