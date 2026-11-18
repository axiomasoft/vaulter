<?php

declare(strict_types=1);

namespace Vaulter\Filament\Support;

use Carbon\CarbonImmutable;
use Packman\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Support\Identity;

/**
 * Flat node rows for the panel table, read through the authorized handle's public
 * listings and ability batches. A node the handle does not list is not a row, and an
 * action is offered only when the handle's abilities allow it.
 *
 * The walk is bounded: the panel is an inspection surface, not a browser, so a very
 * large drive shows its first {@see self::LIMIT} nodes instead of exhausting memory.
 *
 * @phpstan-type NodeRow array{id: string, drive_id: string, parent_id: ?string, kind: NodeKind, name: string, size_bytes: int, trashed_at: ?CarbonImmutable, created_at: CarbonImmutable, abilities: array<string, bool>}
 */
#[Internal]
final class PanelNodes
{
    public const int LIMIT = 2000;

    private const int PAGE = 200;

    private function __construct() {}

    /** @return list<NodeRow> */
    public static function rows(): array
    {
        $rows = [];

        foreach (PanelDrives::handles() as $handle) {
            foreach (self::snapshots($handle, self::LIMIT - count($rows)) as $snapshot) {
                $rows[] = [
                    'id' => $snapshot->id,
                    'drive_id' => $snapshot->driveId,
                    'parent_id' => $snapshot->parentId,
                    'kind' => $snapshot->kind,
                    'name' => $snapshot->name,
                    'size_bytes' => $snapshot->sizeBytes,
                    'trashed_at' => $snapshot->trashedAt === null ? null : CarbonImmutable::instance($snapshot->trashedAt),
                    'created_at' => CarbonImmutable::instance($snapshot->createdAt),
                    'abilities' => [],
                ];
            }
            if (count($rows) >= self::LIMIT) {
                break;
            }
        }

        return self::withAbilities($rows);
    }

    /**
     * Folders of the node's drive the actor may create into, except the node itself and
     * every folder inside it. The move operation rejects that cycle; the picker does too.
     *
     * @return array<string, string> node id => name
     */
    public static function destinations(string $driveId, string $exceptId): array
    {
        $options = [];
        $handle = PanelDrives::handle($driveId);

        if ($handle === null) {
            return [];
        }

        $folders = self::movableFolders($handle, $exceptId);
        $abilities = $handle->nodes()->abilitiesMany(array_map(static fn (NodeSnapshot $node): string => $node->id, $folders));

        foreach ($folders as $folder) {
            if (($abilities[$folder->id] ?? null)?->flags[Ability::Create->value] ?? false) {
                $options[$folder->id] = $folder->name === '' ? '(root)' : $folder->name;
            }
        }

        asort($options);

        return $options;
    }

    /**
     * A node the acting admin may view, with the handle of its drive; null when no listed
     * drive shows it, which is how a hidden node and a missing node look alike.
     *
     * @return array{DriveHandle, NodeSnapshot}|null
     */
    public static function find(int|string|null $id): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }

        foreach (PanelDrives::handles() as $handle) {
            try {
                return [$handle, $handle->nodes()->find((string) $id)];
            } catch (VaulterException) {
                // Not in this drive, or not visible there.
            }
        }

        return null;
    }

    /** @return array<string, string> drive id => name */
    public static function driveOptions(): array
    {
        $options = [];

        foreach (PanelDrives::handles() as $handle) {
            $snapshot = $handle->snapshot();
            $options[$snapshot->id] = $snapshot->name;
        }

        return $options;
    }

    /**
     * Folders the actor could move `$exceptId` into. The node itself and every folder
     * under it are absent: the move operation rejects that cycle, and the picker
     * should not offer it.
     *
     * @return list<NodeSnapshot>
     */
    private static function movableFolders(DriveHandle $handle, string $exceptId): array
    {
        $folders = array_values(array_filter(
            self::snapshots($handle, self::LIMIT, live: true),
            static fn (NodeSnapshot $node): bool => $node->kind === NodeKind::Folder,
        ));
        $children = [];
        foreach ($folders as $folder) {
            if ($folder->parentId !== null) {
                $children[$folder->parentId][] = $folder->id;
            }
        }
        $excluded = [$exceptId => true];
        $pending = [$exceptId];
        while ($pending !== []) {
            $id = array_pop($pending);
            foreach ($children[$id] ?? [] as $child) {
                if (!isset($excluded[$child])) {
                    $excluded[$child] = true;
                    $pending[] = $child;
                }
            }
        }

        return array_values(array_filter(
            $folders,
            static fn (NodeSnapshot $folder): bool => !isset($excluded[$folder->id]),
        ));
    }

    /** @return list<NodeSnapshot> */
    private static function snapshots(DriveHandle $handle, int $limit, bool $live = false): array
    {
        $found = [$handle->root()];
        $queue = [$handle->root()->id];

        try {
            while ($queue !== [] && count($found) < $limit) {
                $folder = array_shift($queue);
                $cursor = null;
                do {
                    $page = $handle->nodes()->children($folder, new ListOptions(perPage: self::PAGE, cursor: $cursor));
                    foreach ($page as $node) {
                        $found[] = $node;
                        if ($node->kind === NodeKind::Folder) {
                            $queue[] = $node->id;
                        }
                    }
                    $cursor = $page->nextCursor;
                } while ($cursor !== null && count($found) < $limit);
            }

            $cursor = null;
            while (!$live && count($found) < $limit) {
                $page = $handle->trash()->list(new ListOptions(perPage: self::PAGE, cursor: $cursor));
                foreach ($page as $node) {
                    $found[] = $node;
                }
                $cursor = $page->nextCursor;
                if ($cursor === null) {
                    break;
                }
            }
        } catch (VaulterException) {
            // A listing the actor may not read contributes what was collected so far.
        }

        return array_slice($found, 0, $limit);
    }

    /**
     * @param  list<NodeRow> $rows
     * @return list<NodeRow>
     */
    private static function withAbilities(array $rows): array
    {
        $byDrive = [];
        foreach ($rows as $row) {
            $byDrive[$row['drive_id']][] = $row['id'];
        }

        $abilities = [];
        foreach ($byDrive as $driveId => $ids) {
            $handle = PanelDrives::handle($driveId);
            if ($handle === null) {
                continue;
            }
            foreach (array_chunk($ids, 200) as $chunk) {
                foreach ($handle->nodes()->abilitiesMany($chunk) as $id => $set) {
                    $abilities[Identity::stringify($id)] = $set->flags;
                }
            }
        }

        return array_map(static function (array $row) use ($abilities): array {
            $row['abilities'] = $abilities[$row['id']] ?? [];

            return $row;
        }, $rows);
    }
}
