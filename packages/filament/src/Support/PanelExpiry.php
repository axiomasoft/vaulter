<?php

declare(strict_types=1);

namespace Vaulter\Filament\Support;

use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Configuration\SharingConfig;

/**
 * Optional expiry typed into the grant and share-link forms.
 *
 * The rules are the ones the HTTP grant and share requests already apply.
 * They run before {@see Carbon::parse()}, so a value the API would reject is a
 * field error instead of an uncaught parse exception. A blank field stays null.
 * A normal datetime string is still parsed by Carbon, unchanged.
 */
#[Internal]
final class PanelExpiry
{
    private function __construct() {}

    /**
     * @return list<string>
     */
    public static function grantRules(): array
    {
        return ['nullable', 'date', 'after:now'];
    }

    /**
     * @return list<string>
     */
    public static function shareRules(): array
    {
        $latest = Carbon::now()->addHours(app(SharingConfig::class)->maxTtlHours())->toDateTimeString();

        return ['nullable', 'date', 'after:now', 'before_or_equal:'.$latest];
    }

    public static function forGrant(mixed $value): ?Carbon
    {
        return self::parse(value: $value, rules: self::grantRules());
    }

    public static function forShare(mixed $value): ?Carbon
    {
        return self::parse(value: $value, rules: self::shareRules());
    }

    /**
     * @param list<string> $rules
     */
    private static function parse(mixed $value, array $rules): ?Carbon
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $validator = Validator::make(
            data: ['expires_at' => $value],
            rules: ['expires_at' => $rules],
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        if (!is_string($value) && !is_int($value) && !is_float($value) && !$value instanceof DateTimeInterface) {
            self::rejectUnparseable();
        }

        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            self::rejectUnparseable();
        }
    }

    private static function rejectUnparseable(): never
    {
        throw ValidationException::withMessages([
            'expires_at' => 'The expires at field must be a valid date.',
        ]);
    }
}
