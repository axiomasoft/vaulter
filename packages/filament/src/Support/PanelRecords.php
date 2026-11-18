<?php

declare(strict_types=1);

namespace Vaulter\Filament\Support;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Pagination\Paginator;
use Packman\Attributes\Internal;

/**
 * The sort and page step every panel table shares: the rows come from authorized drive
 * listings (never an Eloquent query), so search, filters, sorting and the page cut are
 * applied to the already authorized rows in memory, keyed by row id as Filament's record key.
 */
#[Internal]
final class PanelRecords
{
    private function __construct() {}

    /**
     * @param  list<array<string, mixed>>              $rows
     * @return Paginator<string, array<string, mixed>>
     */
    public static function paginate(array $rows, ?string $sortColumn, ?string $sortDirection, int $page, int|string $recordsPerPage, string $defaultColumn): Paginator
    {
        $column = $sortColumn ?? $defaultColumn;
        $sorted = collect($rows)->sortBy(
            static fn (array $row): mixed => self::sortKey($row[$column] ?? null),
            descending: ($sortDirection ?? 'desc') === 'desc',
        )->keyBy(static fn (array $row): string => is_scalar($row['id'] ?? null) ? (string) $row['id'] : '');

        // The per-page select posts its value as a string; only "all" means every row.
        $perPage = is_numeric($recordsPerPage) ? max((int) $recordsPerPage, 1) : max($sorted->count(), 1);

        // One extra row tells the simple paginator whether a next page exists.
        return new Paginator($sorted->slice(($page - 1) * $perPage, $perPage + 1)->all(), $perPage, $page);
    }

    /** @param array<string, mixed> $filters */
    public static function filterValue(array $filters, string $name): ?string
    {
        $entry = $filters[$name] ?? null;
        $value = is_array($entry) ? ($entry['value'] ?? null) : null;

        // A ternary filter hands over a real boolean in tests and the strings "1"/"0" from the browser.
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public static function contains(string $haystack, ?string $needle): bool
    {
        return $needle === null || $needle === '' || str_contains(mb_strtolower($haystack), mb_strtolower($needle));
    }

    public static function humanBytes(int $bytes, string $zero = '—'): string
    {
        if ($bytes <= 0) {
            return $zero;
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = (int) min(floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return sprintf('%s %s', rtrim(rtrim(number_format($value, 2), '0'), '.'), $units[$power]);
    }

    private static function sortKey(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->getTimestamp(),
            default => $value,
        };
    }
}
