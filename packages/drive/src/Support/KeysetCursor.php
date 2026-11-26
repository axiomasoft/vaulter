<?php

declare(strict_types=1);

namespace Vaulter\Drive\Support;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Packman\Attributes\Internal;
use Vaulter\Drive\Exceptions\Nodes\InvalidCursorException;
use Vaulter\Support\Identity;

/**
 * Opens a client's plain Laravel keyset cursor before it reaches cursorPaginate(). The format is unchanged: the
 * cursor is still the base64 JSON Laravel issues. What changes is that a cursor which does not decode, lacks a sort
 * column of this listing (one issued for another order) or carries a value the column cannot hold is refused as an
 * invalid cursor, instead of ending in Laravel's UnexpectedValueException or a failed database cast, both a 500.
 * Parameters other than the listing's sort columns are ignored, as Laravel ignores them.
 */
#[Internal]
final class KeysetCursor
{
    public static function revision(?string $encoded): ?Cursor
    {
        $cursor = self::decode($encoded);
        if ($cursor !== null && !self::isNumber($cursor->toArray()['number'] ?? null)) {
            throw InvalidCursorException::invalid();
        }

        return $cursor;
    }

    private static function decode(?string $encoded): ?Cursor
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }

        $cursor = Cursor::fromEncoded($encoded);
        if (!$cursor instanceof Cursor || !is_bool($cursor->toArray()['_pointsToNextItems'] ?? null)) {
            throw InvalidCursorException::invalid();
        }

        return $cursor;
    }

    private static function byCreationTime(?string $encoded, Model $model): ?Cursor
    {
        $cursor = self::decode($encoded);
        if ($cursor === null) {
            return null;
        }
        $values = $cursor->toArray();
        $key = $values['id'] ?? null;
        if ((!is_int($key) && (!is_string($key) || $key === '')) || !Identity::fitsKey($model, $key) || !array_key_exists('created_at', $values)) {
            throw InvalidCursorException::invalid();
        }
        $timestamp = $values['created_at'];
        if ($timestamp !== null && (!is_string($timestamp) || !self::isTimestamp($timestamp))) {
            throw InvalidCursorException::invalid();
        }

        return $cursor;
    }

    /**
     * The two parts of a nullable index have different seek predicates. Read each
     * part in native index order, continuing into the other only at the boundary.
     *
     * @template T of Model
     *
     * @param  Builder<T>              $listing
     * @return CursorPaginator<int, T>
     */
    public static function pageByCreationTime(Builder $listing, ?string $encoded, int $perPage, bool $newestFirst): CursorPaginator
    {
        $cursor = self::byCreationTime($encoded, $listing->getModel());
        $ascending = $newestFirst === ($cursor?->pointsToPreviousItems() ?? false);
        $nullsFirst = $ascending === ($listing->getModel()->getConnection()->getDriverName() !== 'pgsql');
        $timestamp = $cursor?->parameter('created_at');
        $operator = $ascending ? '>' : '<';
        $direction = $ascending ? 'asc' : 'desc';
        $items = [];
        foreach ([$nullsFirst, !$nullsFirst] as $nulls) {
            $samePartition = $cursor !== null && $nulls === ($timestamp === null);
            if ($cursor !== null && !$samePartition && $nulls === $nullsFirst) {
                continue;
            }

            $query = (clone $listing)->reorder();
            if ($nulls) {
                $query->whereNull('created_at');
                if ($samePartition) {
                    $query->where('id', $operator, $cursor->parameter('id'));
                }
            } else {
                $query->whereNotNull('created_at')->orderBy('created_at', $direction);
                if ($samePartition) {
                    $query->where('created_at', $ascending ? '>=' : '<=', $timestamp)
                        ->where(static fn (Builder $q): Builder => $q->where('created_at', $operator, $timestamp)
                            ->orWhere(static fn (Builder $tie): Builder => $tie->where('created_at', $timestamp)
                                ->where('id', $operator, $cursor->parameter('id'))));
                }
            }
            array_push($items, ...$query->orderBy('id', $direction)->limit($perPage + 1 - count($items))->get()->all());
            if (count($items) > $perPage) {
                break;
            }
        }

        return new CursorPaginator($items, $perPage, $cursor, ['parameters' => ['created_at', 'id']]);
    }

    private static function isNumber(mixed $value): bool
    {
        // File and document revision numbers are signed SQL INTEGER columns.
        if (is_int($value)) {
            return $value >= 0 && $value <= 2_147_483_647;
        }
        if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            return false;
        }
        $digits = ltrim($value, '0');

        return strlen($digits) < 10 || (strlen($digits) === 10 && strcmp($digits, '2147483647') <= 0);
    }

    private static function isTimestamp(string $value): bool
    {
        if (str_starts_with($value, '0000-') || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $value) !== 1) {
            return false;
        }

        $format = str_contains($value, '.') ? '!Y-m-d H:i:s.u' : '!Y-m-d H:i:s';
        $parsed = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }
}
