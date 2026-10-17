<?php

declare(strict_types=1);

namespace Vaulter\Filament\Support;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Events\EventCatalog;
use Vaulter\Exceptions\VaulterException;

/**
 * The audit-feed rows of the panel table, read through each listed drive's activity
 * operations: the drive's own projection decides which entries and which fields the admin sees.
 * The walk is bounded like the node table: the panel inspects, it does not export.
 *
 * @phpstan-type ActivityRow array{id: string, verb: string, verb_label: string, drive_id: ?string, actor_type: ?string, actor_id: ?string, target_type: ?string, target_id: ?string, ip: ?string, user_agent: ?string, request_id: ?string, context: array<string, mixed>, created_at: CarbonImmutable}
 */
#[Internal]
final class PanelActivity
{
    public const int LIMIT = 2000;

    private const int PAGE = 200;

    private function __construct() {}

    /** @return list<ActivityRow> */
    public static function rows(): array
    {
        $rows = [];
        $catalog = app(EventCatalog::class);

        foreach (PanelDrives::handles() as $handle) {
            try {
                $cursor = null;
                do {
                    $page = $handle->activity()->forDrive(new ListOptions(perPage: self::PAGE, cursor: $cursor));
                    foreach ($page as $entry) {
                        $rows[] = [
                            'id' => $entry->id,
                            'verb' => $entry->verb,
                            'verb_label' => $catalog->resolve($entry->verb)?->label() ?? $entry->verb,
                            'drive_id' => $entry->driveId,
                            'actor_type' => $entry->actorType,
                            'actor_id' => $entry->actorId,
                            'target_type' => $entry->targetType,
                            'target_id' => $entry->targetId,
                            'ip' => $entry->ip,
                            'user_agent' => $entry->userAgent,
                            'request_id' => $entry->requestId,
                            'context' => $entry->context,
                            'created_at' => CarbonImmutable::instance($entry->createdAt),
                        ];
                    }
                    $cursor = $page->nextCursor;
                } while ($cursor !== null && count($rows) < self::LIMIT);
            } catch (VaulterException) {
                // Drives without root View contribute no activity.
            }

            if (count($rows) >= self::LIMIT) {
                break;
            }
        }

        return array_slice($rows, 0, self::LIMIT);
    }
}
