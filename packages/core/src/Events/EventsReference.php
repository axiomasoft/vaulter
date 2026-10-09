<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Vaulter\Attributes\EventSpec;
use Vaulter\Enums\Events\EventType;

/**
 * Renders the event catalogue table of the reference docs from the {@see EventSpec}
 * of every {@see EventType}, so the docs cannot drift from the code.
 */
final readonly class EventsReference
{
    public const START = '<!-- events:start -->';

    public const END = '<!-- events:end -->';

    /** The markdown table, without the markers. */
    public static function render(): string
    {
        $lines = [
            '| Type | Subject | Data | Activity | Webhook | Journal | Description |',
            '|---|---|---|---|---|---|---|',
        ];
        foreach (EventType::cases() as $type) {
            $spec = $type->spec();
            $data = $spec->data === []
                ? '—'
                : implode(', ', array_map(
                    static fn (string $key, string $kind): string => sprintf('`%s`: `%s`', $key, str_replace('|', '\|', $kind)),
                    array_keys($spec->data),
                    array_values($spec->data),
                ));
            $lines[] = sprintf(
                '| `%s` | %s | %s | %s | %s | %s | %s |',
                $type->value,
                $spec->subject,
                $data,
                $spec->activity ? '✓' : '—',
                $spec->webhook ? '✓' : '—',
                $spec->journal ?? '—',
                $spec->description,
            );
        }

        return implode("\n", $lines);
    }

    /** The block between the markers, markers included. */
    public static function block(): string
    {
        return self::START."\n".self::render()."\n".self::END;
    }

    /**
     * The page with its generated block replaced; null when the markers are missing.
     */
    public static function apply(string $page): ?string
    {
        $start = strpos($page, self::START);
        $end = strpos($page, self::END);
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        return substr($page, 0, $start).self::block().substr($page, $end + strlen(self::END));
    }
}
