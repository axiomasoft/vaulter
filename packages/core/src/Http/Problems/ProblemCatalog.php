<?php

declare(strict_types=1);

namespace Vaulter\Http\Problems;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use InvalidArgumentException;
use Packman\Attributes\Internal;

/**
 * Every problem code the HTTP surface can answer with: the codes the active modules declare with
 * `ModuleBuilder::problems()` (architecture#errors). The single source of the reference docs, of the
 * `retryable` hint of a problem body, of the receipt policy of idempotent operations and of the
 * localized `title`/`detail`.
 */
#[Internal]
final class ProblemCatalog
{
    public const string TYPE_BASE = 'https://github.com/axiomasoft/vaulter/blob/main/docs/reference/errors.md#';

    /** @var array<string, array{spec: ProblemSpec, module: string, namespace: string}>|null */
    private ?array $entries = null;

    /**
     * @param Closure(): iterable<string, iterable<ProblemCodeSource>> $sources Resolves the sources lazily, by declaring module id
     */
    public function __construct(
        private readonly Closure $sources,
        private readonly Translator $translator,
    ) {}

    /**
     * @return array<string, array{status: int, exception: class-string|null, module: string, retryable: Retryable, receipt: ReceiptPolicy}>
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->entries() as $code => $entry) {
            $all[$code] = [
                'status' => $entry['spec']->status,
                'exception' => $entry['spec']->exception,
                'module' => $entry['module'],
                'retryable' => $entry['spec']->retryable,
                'receipt' => $entry['spec']->receipt,
            ];
        }

        return $all;
    }

    public function has(string $code): bool
    {
        return isset($this->entries()[$code]);
    }

    /** The spec of `$code`, or null when no active module declares it. */
    public function spec(string $code): ?ProblemSpec
    {
        return $this->entries()[$code]['spec'] ?? null;
    }

    /** The module that declares `$code`, or null when no active module declares it. */
    public function module(string $code): ?string
    {
        return $this->entries()[$code]['module'] ?? null;
    }

    /**
     * Whether an idempotent operation stores a failure with `$code` as its receipt. An unknown code is
     * never cached: only a declared deterministic outcome is.
     */
    public function isCacheable(string $code): bool
    {
        return $this->spec($code)?->receipt === ReceiptPolicy::Cacheable;
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
     * @return array<string, array{spec: ProblemSpec, module: string, namespace: string}>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $entries = [];
        foreach (($this->sources)() as $module => $sources) {
            foreach ($sources as $source) {
                foreach ($source->codes() as $code => $spec) {
                    // The module compiler refuses such a graph; a catalogue built by hand refuses it too.
                    if (isset($entries[$code])) {
                        throw new InvalidArgumentException(sprintf('[Vaulter] The problem code "%s" is declared by both the module "%s" and the module "%s".', $code, $entries[$code]['module'], $module));
                    }
                    $entries[$code] = ['spec' => $spec, 'module' => $module, 'namespace' => $source->translationNamespace()];
                }
            }
        }
        ksort($entries);

        return $this->entries = $entries;
    }
}
