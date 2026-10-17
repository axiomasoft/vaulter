<?php

declare(strict_types=1);

namespace Vaulter\Drive\Queries;

use Illuminate\Contracts\Auth\Access\Authorizable;
use InvalidArgumentException;
use JsonException;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Page;
use Vaulter\Drive\Contracts\DriveHandleFactory;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\Snapshots\DriveSnapshot;
use Vaulter\Drive\Enums\NodeSort;
use Vaulter\Drive\Exceptions\Nodes\InvalidCursorException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Repositories\DriveReadRepository;
use Vaulter\Exceptions\VaulterException;

/**
 * The drives an actor may open: a drive is listed only when the actor's own handle
 * answers View on its root, the same decision every operation of that drive makes. The
 * decision is made before a drive takes a slot of the page, so a hidden drive is neither
 * listed nor counted, and the cursor only ever names a drive the actor may see.
 */
#[Internal]
final readonly class VisibleDrivesQuery
{
    private const int CHUNK = 100;

    public function __construct(private DriveReadRepository $drives) {}

    /** @return Page<DriveSnapshot> */
    public function execute(DriveHandleFactory $factory, Authorizable $actor, ListOptions $options): Page
    {
        if ($options->sort !== NodeSort::Default || $options->descending || $options->kinds !== []) {
            throw new InvalidArgumentException('Drive lists support perPage and cursor only.');
        }

        $after = $this->decode($options->cursor);
        $items = [];
        $lastKey = null;

        while (true) {
            $chunk = $this->drives->after($after, self::CHUNK);

            foreach ($chunk as $drive) {
                $key = $drive->getKey();
                if (!is_int($key) && !is_string($key)) {
                    continue;
                }
                $after = $key;
                $snapshot = $this->visible($factory, $actor, $drive);
                if ($snapshot === null) {
                    continue;
                }
                if (count($items) === $options->perPage) {
                    return new Page(items: $items, nextCursor: $this->encode($lastKey), scanned: 0);
                }
                $items[] = $snapshot;
                $lastKey = $key;
            }

            if (count($chunk) < self::CHUNK) {
                return new Page(items: $items, nextCursor: null, scanned: 0);
            }
        }
    }

    private function visible(DriveHandleFactory $factory, Authorizable $actor, Drive $drive): ?DriveSnapshot
    {
        try {
            $handle = $factory->forDrive($drive)->actingAs($actor);
            $handle->root();

            return $handle->snapshot();
        } catch (VaulterException) {
            return null;
        }
    }

    private function encode(int|string|null $key): ?string
    {
        if ($key === null) {
            return null;
        }

        return rtrim(strtr(base64_encode(json_encode(['k' => $key], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function decode(?string $cursor): int|string|null
    {
        if ($cursor === null) {
            return null;
        }

        try {
            $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidCursorException::invalid();
        }

        $key = is_array($decoded) ? ($decoded['k'] ?? null) : null;

        return is_int($key) || (is_string($key) && $key !== '') ? $key : throw InvalidCursorException::invalid();
    }
}
