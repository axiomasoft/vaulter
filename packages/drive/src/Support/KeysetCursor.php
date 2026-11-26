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
    /** A non-negative whole number, as a revision number. */
    public const string NUMBER = 'number';

    /** A timestamp as Laravel writes it from the model: `Y-m-d H:i:s`, optionally with microseconds. */
    public const string TIMESTAMP = 'timestamp';

    /** The primary key of the listed model. */
    public const string KEY = 'key';

    /**
     * @param array<string, self::NUMBER|self::TIMESTAMP|self::KEY> $columns sort column => the kind of its value
     * @param Model                                                 $model   the listed model, for the key column
     *
     * @throws InvalidCursorException when the cursor is malformed or does not fit this listing
     */
    public static function open(?string $encoded, array $columns, Model $model): ?Cursor
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }
        $cursor = Cursor::fromEncoded($encoded);
        if (!$cursor instanceof Cursor) {
            throw InvalidCursorException::invalid();
        }
        $parameters = $cursor->toArray();
        if (!is_bool($parameters['_pointsToNextItems'] ?? null)) {
            throw InvalidCursorException::invalid();
        }
        foreach ($columns as $column => $kind) {
            $value = $parameters[$column] ?? null;
            $fits = match ($kind) {
                self::NUMBER => self::isNumber($value),
                self::TIMESTAMP => array_key_exists($column, $parameters) && ($value === null || (is_string($value) && self::isTimestamp($value))),
                self::KEY => (is_int($value) || (is_string($value) && $value !== '')) && Identity::fitsKey($model, $value),
            };
            if (!$fits) {
                throw InvalidCursorException::invalid();
            }
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
        $cursor = self::open($encoded, ['created_at' => self::TIMESTAMP, 'id' => self::KEY], $listing->getModel());
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
        return (is_int($value) && $value >= 0)
            || (is_string($value) && preg_match('/^[0-9]{1,18}$/D', $value) === 1);
    }

    private static function isTimestamp(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $value) !== 1) {
            return false;
        }

        $format = str_contains($value, '.') ? '!Y-m-d H:i:s.u' : '!Y-m-d H:i:s';
        $parsed = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }
}
