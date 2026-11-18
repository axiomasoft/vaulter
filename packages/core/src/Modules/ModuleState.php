<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Packman\Attributes\Api;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;

/**
 * An offered module after compilation: whether it is active and why.
 */
#[Api]
final readonly class ModuleState
{
    public const string REASON_DEFAULT = 'default';

    public const string REASON_OVERRIDE = 'override';

    /**
     * @param class-string<ExtensionPlugin> $manifest
     * @param list<string>                  $schemaPaths
     */
    public function __construct(
        public ModuleId $id,
        /** @var class-string<ExtensionPlugin> */
        public string $manifest,
        public string $version,
        public Activation $activation,
        public bool $active,
        public string $activeReason,
        public array $schemaPaths = [],
    ) {}
}
