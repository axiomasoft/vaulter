<?php

declare(strict_types=1);

namespace Vaulter\Http\Problems;

use Closure;
use Illuminate\Contracts\Translation\Translator;

/**
 * Every problem code the HTTP surface can answer with. The single source of the
 * reference docs and of the localized `title`/`detail` of a problem body.
 */
final class ProblemCatalog
{
    public const string TAG = 'vaulter.problem_codes';

    public const string TYPE_BASE = 'https://github.com/axiomasoft/vaulter/blob/main/docs/reference/errors.md#';

    /** @var array<string, array{status: int, exception: class-string|null, package: string, namespace: string}>|null */
    private ?array $entries = null;

    /**
     * @param Closure(): iterable<mixed> $sources Resolves the {@see ProblemCodeSource}s lazily, once all providers have registered
     */
    public function __construct(
        private readonly Closure $sources,
        private readonly Translator $translator,
    ) {}

    /**
     * @return array<string, array{status: int, exception: class-string|null, package: string}>
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->entries() as $code => $entry) {
            $all[$code] = ['status' => $entry['status'], 'exception' => $entry['exception'], 'package' => $entry['package']];
        }

        return $all;
    }

    public function has(string $code): bool
    {
        return isset($this->entries()[$code]);
    }

    public function type(string $code): string
    {
        return self::TYPE_BASE.$code;
    }

    /**
     * The localized title, or null when the code is not in the catalogue or has no title
     * in the active locale or the fallback.
     */
    public function title(string $code, ?string $locale = null): ?string
    {
        return $this->line($code, 'title', [], $locale);
    }

    /**
     * The localized detail with `:placeholders` filled from scalar meta values, or null
     * when the code has none (the body then carries `detail: null`).
     *
     * @param array<array-key, mixed> $meta
     */
    public function detail(string $code, array $meta = [], ?string $locale = null): ?string
    {
        $replace = [];
        foreach ($meta as $key => $value) {
            if (is_scalar($value)) {
                $replace[(string) $key] = (string) $value;
            }
        }

        return $this->line($code, 'detail', $replace, $locale);
    }

    /**
     * @param array<string, string> $replace
     */
    private function line(string $code, string $field, array $replace, ?string $locale): ?string
    {
        $entry = $this->entries()[$code] ?? null;
        if ($entry === null) {
            return null;
        }

        $key = sprintf('%s::problems.%s.%s', $entry['namespace'], $code, $field);
        $line = $this->translator->get($key, $replace, $locale);

        // A missing line comes back as the key itself.
        return is_string($line) && $line !== $key ? $line : null;
    }

    /**
     * @return array<string, array{status: int, exception: class-string|null, package: string, namespace: string}>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $entries = [];
        foreach (($this->sources)() as $source) {
            if (!$source instanceof ProblemCodeSource) {
                continue;
            }
            foreach ($source->codes() as $code => $spec) {
                // Sources register in tag order, core first; a code is claimed once.
                $entries[$code] ??= $spec + ['package' => $source->package(), 'namespace' => $source->translationNamespace()];
            }
        }
        ksort($entries);

        return $this->entries = $entries;
    }
}
