<?php

declare(strict_types=1);

namespace Vaulter\Documents\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use JsonException;
use Vaulter\Attributes\Internal;

/** The size and nesting bound shared by nullable HTTP array anchors and the PHP input. */
#[Internal]
final class CommentAnchorBounds implements ValidationRule
{
    private const int MAX_BYTES = 16384;

    private const int MAX_DEPTH = 16;

    /** @param Closure(string): PotentiallyTranslatedString $fail */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // The request's array rule owns shape errors; this rule bounds only valid array inputs.
        if ($value !== null && !is_array($value)) {
            return;
        }
        $error = self::error($value);
        if ($error !== null) {
            $fail($error);
        }
    }

    /** @param array<array-key, mixed>|null $anchor */
    public static function error(?array $anchor): ?string
    {
        if ($anchor === null) {
            return null;
        }

        try {
            // JSON_THROW_ON_ERROR changes failure handling only: retain default escaping when counting bytes.
            $encoded = json_encode($anchor, JSON_THROW_ON_ERROR, self::MAX_DEPTH);
        } catch (JsonException) {
            return 'A comment anchor must be valid JSON with at most 16 levels.';
        }

        return strlen($encoded) > self::MAX_BYTES
            ? 'A comment anchor must encode to at most 16384 JSON bytes.'
            : null;
    }
}
