<?php

declare(strict_types=1);

namespace Vaulter\Media\Cas;

use Vaulter\Attributes\Internal;
use Vaulter\Modules\ModuleReferences;

/**
 * The pins as references between modules, for `vaulter:module:uninstall` (D36, D23): a module keeps the pins of its
 * own owner types `<module>.*`; the pins of any other owner type on the media's blobs refuse the removal of the media,
 * whether their owner is installed or not (they are released only by the purge of their owner). Before the media's
 * schema is installed there are no pins.
 */
#[Internal]
final readonly class BlobPinReferences implements ModuleReferences
{
    private const string MEDIA = 'media';

    public function __construct(private BlobPinStore $pins) {}

    public function keptBy(string $module): array
    {
        return $this->pinsWhere(static fn (string $ownerType): bool => self::ownedBy($ownerType, $module));
    }

    public function keptOn(string $module): array
    {
        if ($module !== self::MEDIA) {
            return [];
        }

        return $this->pinsWhere(static fn (string $ownerType): bool => !self::ownedBy($ownerType, self::MEDIA));
    }

    public function releaseKeptBy(string $module): int
    {
        $released = 0;
        foreach (array_keys($this->keptBy($module)) as $ownerType) {
            $released += $this->pins->releaseOwner($ownerType);
        }

        return $released;
    }

    /**
     * @param  callable(string): bool $keep
     * @return array<string, int>
     */
    private function pinsWhere(callable $keep): array
    {
        if (!$this->pins->installed()) {
            return [];
        }

        return array_filter(
            $this->pins->countByOwner(),
            static fn (int $count, string $ownerType): bool => $count > 0 && $keep($ownerType),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private static function ownedBy(string $ownerType, string $module): bool
    {
        return str_starts_with($ownerType, $module.'.');
    }
}
