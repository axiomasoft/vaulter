<?php

declare(strict_types=1);

namespace Vaulter\Http\Problems;

use Packman\Attributes\Internal;

/**
 * Renders the error reference of the docs from the {@see ProblemCatalog}, so the docs
 * cannot drift from the codes: one summary table (status, declaring module, retryability
 * and idempotency receipt policy), then a heading per code, which is the anchor the
 * `type` URI of a problem body points at.
 */
#[Internal]
final readonly class ErrorsReference
{
    public const string START = '<!-- vaulter:errors:start -->';

    public const string END = '<!-- vaulter:errors:end -->';

    public function __construct(private ProblemCatalog $catalog) {}

    /** The markdown between the markers, without them. */
    public function render(string $locale): string
    {
        $summary = [
            '| Code | HTTP | Module | Retryable | Receipt | Exception |',
            '|---|---|---|---|---|---|',
        ];
        $sections = [];
        foreach ($this->catalog->all() as $code => $entry) {
            $exception = $entry['exception'] === null ? '—' : '`'.$entry['exception'].'`';
            $summary[] = sprintf(
                '| [`%s`](#%s) | %d | %s | `%s` | `%s` | %s |',
                $code,
                $code,
                $entry['status'],
                $entry['module'],
                $entry['retryable']->value,
                $entry['receipt']->value,
                $exception,
            );

            $section = ['### `'.$code.'`', '', sprintf('**%d** · %s · retryable `%s` · receipt `%s`', $entry['status'], $entry['module'], $entry['retryable']->value, $entry['receipt']->value)];
            $title = $this->catalog->title($code, $locale);
            $detail = $this->catalog->detail($code, [], $locale);
            if ($title !== null) {
                $section[] = '';
                $section[] = '**'.$title.'**'.($detail === null ? '' : ' — '.$detail);
            }
            $sections[] = implode("\n", $section);
        }

        return implode("\n", $summary)."\n\n".implode("\n\n", $sections);
    }

    /**
     * The machine table of the codes for `openapi/vaulter-v1.yaml`, as the YAML the Problem schema carries: the
     * `x-vaulter-problems` member (code to status, retryable, receipt and declaring module) and the sorted `examples` of
     * the `code` property. Printed by `vaulter:errors:reference --openapi`; the contract test compares the document to it.
     */
    public function openApi(): string
    {
        $lines = ['x-vaulter-problems:'];
        foreach ($this->catalog->all() as $code => $entry) {
            $lines[] = sprintf(
                '  %s: {status: %d, retryable: %s, receipt: %s, module: %s}',
                $code,
                $entry['status'],
                $entry['retryable']->value,
                $entry['receipt']->value,
                $entry['module'],
            );
        }
        $lines[] = 'examples:';
        foreach (array_keys($this->catalog->all()) as $code) {
            $lines[] = '- '.$code;
        }

        return implode("\n", $lines);
    }

    /** The block between the markers, markers included. */
    public function block(string $locale): string
    {
        return self::START."\n".$this->render($locale)."\n".self::END;
    }

    /**
     * The page with its generated block replaced; null when the markers are missing.
     */
    public function apply(string $page, string $locale): ?string
    {
        $start = strpos($page, self::START);
        $end = strpos($page, self::END);
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        return substr($page, 0, $start).$this->block($locale).substr($page, $end + strlen(self::END));
    }
}
