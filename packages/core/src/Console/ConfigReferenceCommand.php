<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Vaulter\Attributes\Internal;

/**
 * Regenerates the key table of the reference docs from the config files of every
 * package: key, env override, default and the comment above the key. With --check
 * nothing is written and the exit code says whether the docs are current (for CI).
 *
 * The files are read with the tokenizer instead of being included, so a default that
 * calls env() shows its variable and fallback rather than the value of this machine.
 */
#[Internal]
final class ConfigReferenceCommand extends Command
{
    /** @var list<bool> per token: whether whitespace preceded it in the file */
    private static array $gaps = [];

    /** @var list<int> per token: the line its last character sits on */
    private static array $endLines = [];

    public const string START = '<!-- vaulter:config-reference:start -->';

    public const string END = '<!-- vaulter:config-reference:end -->';

    protected $signature = 'vaulter:config:reference
        {--check : Exit 1 when a page differs from the generated table, without writing}
        {--path=* : Pages to update (default: docs/reference/config.md and docs/ru/reference/config.md)}';

    protected $description = 'Generate the config key table in the reference docs.';

    /**
     * The markdown tables of every config file under $packages (one section per file),
     * without the markers.
     */
    public static function render(string $packages): string
    {
        $files = glob($packages.'/*/config/*.php') ?: [];
        sort($files);

        $sections = [];
        foreach ($files as $file) {
            $root = basename($file, '.php');
            $rows = self::rows((string) file_get_contents($file), $root);
            if ($rows === []) {
                continue;
            }
            $lines = ['### `'.$root.'`', '', '| Key | Env | Default | Description |', '|---|---|---|---|'];
            foreach ($rows as [$key, $env, $default, $comment]) {
                $lines[] = sprintf('| `%s` | %s | %s | %s |', $key, $env === '' ? '—' : '`'.$env.'`', $default, $comment === '' ? '—' : $comment);
            }
            $sections[] = implode("\n", $lines);
        }

        return implode("\n\n", $sections);
    }

    /** The block between the markers, markers included. */
    public static function block(string $packages): string
    {
        return self::START."\n".self::render($packages)."\n".self::END;
    }

    /** The page with its generated block replaced; null when the markers are missing. */
    public static function apply(string $page, string $packages): ?string
    {
        $start = strpos($page, self::START);
        $end = strpos($page, self::END);
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        return substr($page, 0, $start).self::block($packages).substr($page, $end + strlen(self::END));
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}> key, env, default, comment
     */
    private static function rows(string $source, string $root): array
    {
        $tokens = [];
        self::$gaps = [];
        self::$endLines = [];
        $line = 1;
        $gap = false;
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $line += substr_count($text, "\n");
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                $gap = true;

                continue;
            }
            $tokens[] = $token;
            self::$gaps[] = $gap;
            self::$endLines[] = $line;
            $gap = false;
        }

        // The returned array literal is the first `return [` of the file.
        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_RETURN && ($tokens[$index + 1] ?? null) === '[') {
                $rows = [];
                $position = $index + 1;
                self::walk($tokens, $position, $root, $rows);

                return $rows;
            }
        }

        throw new RuntimeException('No returned array in the config file of '.$root.'.');
    }

    /**
     * Reads the array literal opening at $position and appends one row per leaf key.
     * An array whose keys are strings is a branch; any other array is a leaf default.
     *
     * @param list<array{int, string, int}|string>                    $tokens
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $rows
     */
    private static function walk(array $tokens, int &$position, string $path, array &$rows): void
    {
        $position++; // past '['
        $comment = [];

        while (isset($tokens[$position]) && $tokens[$position] !== ']') {
            $token = $tokens[$position];
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $comment[] = self::commentText($token[1]);
                $position++;

                continue;
            }

            $key = null;
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && ($tokens[$position + 1][0] ?? null) === T_DOUBLE_ARROW) {
                $key = trim($token[1], '\'"');
                $position += 2;
            }

            $start = $position;
            if (($tokens[$position] ?? null) === '[' && self::isBranch($tokens, $position)) {
                // The comment above a group documents the group itself (for example the schedule grammar).
                if ($comment !== []) {
                    $rows[] = [$path.'.'.$key, '', '—', str_replace('|', '\|', implode(' ', $comment))];
                }
                self::walk($tokens, $position, $path.'.'.$key, $rows);
                $position++; // past ']'
                $position += (($tokens[$position] ?? null) === ',') ? 1 : 0;
                $comment = [];

                continue;
            }

            $depth = 0;
            while (isset($tokens[$position])) {
                $current = $tokens[$position];
                if ($current === '[' || $current === '(' || $current === '{') {
                    $depth++;
                } elseif ($current === ']' || $current === ')' || $current === '}') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($current === ',' && $depth === 0) {
                    break;
                }
                $position++;
            }
            $expression = self::source($tokens, $start, $position);
            if (($tokens[$position] ?? null) === ',') {
                $position++;
                // A comment after the comma on the same line belongs to this key.
                $next = $tokens[$position] ?? null;
                if (is_array($next) && $next[0] === T_COMMENT && $next[2] === (self::$endLines[$position - 1] ?? 0)) {
                    $comment[] = self::commentText($next[1]);
                    $position++;
                }
            }

            if ($key !== null) {
                [$env, $default] = self::envAndDefault($expression);
                $rows[] = [$path.'.'.$key, $env, '`'.str_replace('|', '\|', $default).'`', str_replace('|', '\|', implode(' ', $comment))];
            }
            $comment = [];
        }
    }

    /**
     * True when the array opening at $position has at least one string key at its own level.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function isBranch(array $tokens, int $position): bool
    {
        $depth = 0;
        for ($i = $position; isset($tokens[$i]); $i++) {
            $token = $tokens[$i];
            if ($token === '[' || $token === '(' || $token === '{') {
                $depth++;
            } elseif ($token === ']' || $token === ')' || $token === '}') {
                if (--$depth === 0) {
                    return false;
                }
            } elseif ($depth === 1 && is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_ARROW) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: string, 1: string} env variable and the default expression */
    private static function envAndDefault(string $expression): array
    {
        if (preg_match('/^env\(\s*\'([A-Z0-9_]+)\'\s*(?:,\s*(.*))?\)$/s', $expression, $match) === 1) {
            return [$match[1], self::collapse($match[2] ?? 'null')];
        }

        return ['', self::collapse($expression)];
    }

    /** @param  list<array{int, string, int}|string>  $tokens */
    private static function source(array $tokens, int $from, int $to): string
    {
        $text = '';
        for ($i = $from; $i < $to; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                continue;
            }
            $text .= ($i > $from && self::$gaps[$i] ? ' ' : '').(is_array($token) ? $token[1] : $token);
        }

        return $text;
    }

    private static function collapse(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;

        return str_replace(['( ', ' )', '[ ', ' ]', ',]', ', ]'], ['(', ')', '[', ']', ']', ']'], $text);
    }

    private static function commentText(string $comment): string
    {
        // Banner comments frame their text with |---- rules; only the words are documentation.
        $lines = array_map(
            static fn (string $line): string => trim($line, " \t\r|*/-="),
            explode("\n", $comment),
        );

        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($lines, static fn (string $line): bool => $line !== ''))) ?? '');
    }

    public function handle(): int
    {
        $paths = array_values(array_filter((array) $this->option('path'), is_string(...)));
        // The pages and the package configs live in the monorepo, not in the host application.
        $root = dirname(__DIR__, 4);
        if ($paths === []) {
            $paths = [$root.'/docs/reference/config.md', $root.'/docs/ru/reference/config.md'];
        }
        $check = (bool) $this->option('check');
        $stale = false;

        foreach ($paths as $path) {
            $page = is_file($path) ? file_get_contents($path) : false;
            $next = $page === false ? null : self::apply($page, $root.'/packages');
            if ($page === false || $next === null) {
                $this->components->error(sprintf('%s is missing or has no %s / %s markers.', $path, self::START, self::END));
                $stale = true;

                continue;
            }
            if ($next === $page) {
                continue;
            }
            if ($check) {
                $this->components->error($path.' differs from the generated config table; run vaulter:config:reference.');
                $stale = true;

                continue;
            }
            file_put_contents($path, $next);
            $this->components->info('Updated '.$path);
        }

        return $stale ? self::FAILURE : self::SUCCESS;
    }
}
