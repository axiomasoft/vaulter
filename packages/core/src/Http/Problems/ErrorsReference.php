<?php

declare(strict_types=1);

namespace Vaulter\Http\Problems;

/**
 * Renders the error reference of the docs from the {@see ProblemCatalog}, so the docs
 * cannot drift from the codes: one summary table, then a heading per code, which is the
 * anchor the `type` URI of a problem body points at.
 */
final readonly class ErrorsReference
{
    public const START = '<!-- vaulter:errors:start -->';

    public const END = '<!-- vaulter:errors:end -->';

    public function __construct(private ProblemCatalog $catalog) {}

    /** The markdown between the markers, without them. */
    public function render(string $locale): string
    {
        $summary = [
            '| Code | HTTP | Package | Exception |',
            '|---|---|---|---|',
        ];
        $sections = [];
        foreach ($this->catalog->all() as $code => $entry) {
            $exception = $entry['exception'] === null ? '—' : '`'.$entry['exception'].'`';
            $summary[] = sprintf('| [`%s`](#%s) | %d | %s | %s |', $code, $code, $entry['status'], $entry['package'], $exception);

            $section = ['### `'.$code.'`', '', sprintf('**%d** · %s', $entry['status'], $entry['package'])];
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
