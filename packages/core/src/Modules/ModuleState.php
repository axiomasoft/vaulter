<?php

declare(strict_types=1);

namespace Vaulter\Modules;

/**
 * An offered module after compilation: whether it is active and why.
 */
final readonly class ModuleState
{
    public const string REASON_DEFAULT = 'default';

    public const string REASON_OVERRIDE = 'override';

    /**
     * @param class-string<VaulterModule> $manifest
     * @param list<string>                $schemaPaths
     */
    public function __construct(
        public ModuleId $id,
        public string $manifest,
        public string $version,
        public Activation $activation,
        public bool $active,
        public string $activeReason,
        public array $schemaPaths = [],
    ) {}
}
