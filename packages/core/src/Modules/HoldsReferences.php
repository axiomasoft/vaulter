<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Vaulter\Attributes\Internal;

/**
 * A module manifest whose module holds references other modules keep in its data (the media and its pins): it
 * names the class that reads and releases them. Static, so the kernel reads it from the compiled manifest class of an
 * inactive module too — `vaulter:module:uninstall` then refuses to act on references it cannot read.
 */
#[Internal]
interface HoldsReferences
{
    /** @return class-string<ModuleReferences> */
    public static function heldReferences(): string;
}
