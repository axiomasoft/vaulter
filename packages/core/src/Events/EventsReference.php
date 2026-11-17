<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Packman\Attributes\Internal;
use Vaulter\Attributes\EventSpec;

/**
 * Renders the event catalogue table of the reference docs from the {@see EventSpec} of every type in the
 * {@see EventCatalog} of the installed modules, so the docs cannot drift from the code.
 */
#[Internal]
final readonly class EventsReference
{
    public const string START = '<!-- events:start -->';

    public const string END = '<!-- events:end -->';

    public function __construct(private EventCatalog $catalog) {}

    /** The markdown table, without the markers. */
    public function render(): string
    {
        $lines = [
            '| Type | Module | Subject | Data | Activity | External | Journal | Description |',
            '|---|---|---|---|---|---|---|---|',
        ];
        foreach ($this->catalog->all() as $type) {
            $spec = $type->spec();
            $data = $spec->data === []
                ? '—'
                : implode(', ', array_map(
                    static fn (string $key, string $kind): string => sprintf('`%s`: `%s`', $key, str_replace('|', '\|', $kind)),
                    array_keys($spec->data),
                    array_values($spec->data),
                ));
            $lines[] = sprintf(
                '| `%s` | %s | %s | %s | %s | %s | %s | %s |',
                $type->value,
                $this->catalog->owner($type->value)->value,
                $spec->subject,
                $data,
                $spec->activity ? '✓' : '—',
                $spec->external ? '✓' : '—',
                $spec->journal ?? '—',
                $spec->description,
            );
        }

        return implode("\n", $lines);
    }

    /** The block between the markers, markers included. */
    public function block(): string
    {
        return self::START."\n".$this->render()."\n".self::END;
    }

    /**
     * The page with its generated block replaced; null when the markers are missing.
     */
    public function apply(string $page): ?string
    {
        $start = strpos($page, self::START);
        $end = strpos($page, self::END);
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        return substr($page, 0, $start).$this->block().substr($page, $end + strlen(self::END));
    }
}
