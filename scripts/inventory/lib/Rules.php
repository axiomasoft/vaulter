<?php

declare(strict_types=1);

namespace Vaulter\Inventory;

/**
 * Ownership rules applied to subjects (types, files, members): one winner per subject, no conflicts, no stale rule,
 * every rule file answerable only for its own scope. The rule files are hand-written; this class only judges them.
 *
 * Priority: `fqcn` beats `prefix`; among prefixes the longest wins. A subject belongs to the rule file whose scope
 * element matches it the longest (an FQCN prefix, an exact FQCN, or `path:<repo-relative prefix>` for files).
 *
 * @phpstan-type Rule array<string, mixed>
 * @phpstan-type Subject array{kind: string, key: string}
 * @phpstan-type Winner array{id: string, file: string, rule: Rule}
 * @phpstan-type Problem array{code: string, message: string, files: list<string>}
 * @phpstan-type Resolution array{winners: array<string, Winner>, problems: list<Problem>}
 */
final class Rules
{
    public const MATCHES = ['fqcn', 'prefix', 'file', 'member'];

    /** Which rule matches can classify which subject kind. */
    public const SUBJECT_MATCHES = ['type' => ['fqcn', 'prefix'], 'file' => ['file'], 'member' => ['member']];

    /** @var array<string, list<string>> rule file => scope elements */
    private array $scopes = [];

    /** @var list<array{id: string, file: string, rule: Rule}> */
    private array $rules = [];

    /** @var list<Problem> */
    private array $problems = [];

    /**
     * @param array<string, array<string, mixed>> $files  basename (without .json) => {scope: list<string>, rules: list<Rule>}
     * @param list<string>                        $owners when given, a rule owner outside it is an invalid rule
     */
    private function __construct(array $files, array $owners)
    {
        ksort($files, SORT_STRING);
        $seen = [];
        foreach ($files as $file => $data) {
            $scope = $data['scope'] ?? null;
            $rules = $data['rules'] ?? null;
            if (!is_array($scope) || $scope === [] || array_filter($scope, static fn (mixed $s): bool => !is_string($s) || $s === '') !== []) {
                throw new InventoryError("rules {$file}: \"scope\" must be a non-empty list of strings");
            }
            if (!is_array($rules) || !array_is_list($rules)) {
                throw new InventoryError("rules {$file}: \"rules\" must be a list");
            }
            /** @var list<string> $scope */
            $this->scopes[$file] = $scope;

            foreach ($rules as $i => $rule) {
                if (!is_array($rule) || !$this->valid($file, $i, $rule, $owners)) {
                    continue;
                }
                /** @var Rule $rule */
                $id = sprintf('%s:%s:%s', $file, $rule['match'], $rule['value']);
                $this->rules[] = ['id' => $id, 'file' => $file, 'rule' => $rule];
                $seen[$rule['match'].'|'.$rule['value']][] = $file;
            }
        }

        ksort($seen, SORT_STRING);
        foreach ($seen as $key => $in) {
            if (count($in) > 1) {
                [$match, $value] = explode('|', $key, 2);
                $this->problems[] = ['code' => 'conflict', 'message' => sprintf('%s "%s" is declared %d times (%s)', $match, $value, count($in), implode(', ', $in)), 'files' => array_values(array_unique($in))];
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $files
     * @param list<string>                        $owners
     */
    public static function fromArray(array $files, array $owners = []): self
    {
        return new self($files, $owners);
    }

    /**
     * Loads every `<dir>/*.json`; the basename is the rule file's name.
     *
     * @param list<string> $owners
     */
    public static function load(Repo $repo, string $dir, array $owners = []): self
    {
        $files = [];
        foreach (glob($repo->path($dir).'/*.json') ?: [] as $path) {
            $files[basename($path, '.json')] = $repo->readJson($dir.'/'.basename($path));
        }

        return new self($files, $owners);
    }

    /** @return list<string> rule file names */
    public function files(): array
    {
        return array_keys($this->scopes);
    }

    /**
     * @param  list<Subject> $subjects
     * @param  string|null   $only     keep only the problems that concern this rule file (unit-level check)
     * @return Resolution
     */
    public function resolve(array $subjects, ?string $only = null): array
    {
        $problems = $this->problems;
        $winners = [];
        $won = [];

        usort($subjects, static fn (array $a, array $b): int => [$a['kind'], $a['key']] <=> [$b['kind'], $b['key']]);
        foreach ($subjects as $subject) {
            $label = $subject['kind'].' '.$subject['key'];
            [$owner, $tie] = $this->responsible($subject);
            if ($tie !== []) {
                $problems[] = ['code' => 'scope-tie', 'message' => "{$label}: scope matched equally by ".implode(', ', $tie), 'files' => $tie];
            } elseif ($owner === null) {
                $problems[] = ['code' => 'uncovered', 'message' => "{$label}: no rule file scope covers it", 'files' => []];
            }

            $winner = $this->winner($subject);
            if ($winner === null) {
                $problems[] = ['code' => 'unclassified', 'message' => "{$label}: no rule classifies it", 'files' => $owner === null ? $tie : [$owner]];

                continue;
            }
            $winners[$subject['kind'].':'.$subject['key']] = $winner;
            $won[$winner['id']] = true;
            if ($owner !== null && $winner['file'] !== $owner) {
                $problems[] = ['code' => 'out-of-scope', 'message' => sprintf('%s: rule %s is outside the scope of its file (the subject belongs to %s)', $label, $winner['id'], $owner), 'files' => [$winner['file'], $owner]];
            }
        }

        foreach ($this->rules as $item) {
            if (!isset($won[$item['id']])) {
                $problems[] = ['code' => 'stale-rule', 'message' => "rule {$item['id']} classifies nothing", 'files' => [$item['file']]];
            }
        }

        if ($only !== null) {
            $problems = array_values(array_filter($problems, static fn (array $p): bool => in_array($only, $p['files'], true)));
        }

        return ['winners' => $winners, 'problems' => $problems];
    }

    /**
     * @param  list<Problem> $problems
     * @return list<string>
     */
    public static function describe(array $problems): array
    {
        return array_map(static fn (array $p): string => sprintf('[%s] %s', $p['code'], $p['message']), $problems);
    }

    /**
     * @param  Subject     $subject
     * @return Winner|null
     */
    private function winner(array $subject): ?array
    {
        $best = null;
        $bestRank = -1;
        foreach ($this->rules as $item) {
            $match = $item['rule']['match'];
            $value = $item['rule']['value'];
            if (!in_array($match, self::SUBJECT_MATCHES[$subject['kind']] ?? [], true)) {
                continue;
            }
            $key = $subject['key'];
            // fqcn/file/member are exact; a prefix ranks by its length, always below an exact rule.
            $rank = match ($match) {
                'prefix' => str_starts_with($key, $value) ? strlen($value) : -1,
                default => $key === $value ? PHP_INT_MAX : -1,
            };
            if ($rank > $bestRank) {
                $best = $item;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /**
     * The rule file that answers for a subject: the longest matching scope element wins.
     *
     * @param  Subject                            $subject
     * @return array{0: ?string, 1: list<string>} [owner, files tied at the same length]
     */
    private function responsible(array $subject): array
    {
        $best = [];
        $bestLength = -1;
        foreach ($this->scopes as $file => $scope) {
            foreach ($scope as $element) {
                $isPath = str_starts_with($element, 'path:');
                $prefix = $isPath ? substr($element, 5) : $element;
                if ($isPath !== ($subject['kind'] === 'file') || !$this->inScope($subject['key'], $prefix, $isPath)) {
                    continue;
                }
                $length = strlen($prefix);
                if ($length > $bestLength) {
                    $best = [$file => true];
                    $bestLength = $length;
                } elseif ($length === $bestLength) {
                    $best[$file] = true;
                }
            }
        }
        $files = array_keys($best);

        return count($files) === 1 ? [$files[0], []] : [null, $files];
    }

    /**
     * A path scope and an FQCN scope ending in a backslash are prefixes; any other FQCN scope is that exact type
     * (and, for a member subject, the members of it).
     */
    private function inScope(string $key, string $element, bool $isPath): bool
    {
        if ($isPath || str_ends_with($element, '\\')) {
            return str_starts_with($key, $element);
        }

        return $key === $element || str_starts_with($key, $element.'::');
    }

    /**
     * @param array<array-key, mixed> $rule
     * @param list<string>            $owners
     */
    private function valid(string $file, int $index, array $rule, array $owners): bool
    {
        $where = "rules {$file} #{$index}";
        foreach (['match', 'value', 'owner', 'why'] as $field) {
            if (!is_string($rule[$field] ?? null) || trim($rule[$field]) === '') {
                $this->problems[] = ['code' => 'invalid-rule', 'message' => "{$where}: \"{$field}\" is required", 'files' => [$file]];

                return false;
            }
        }
        if (!in_array($rule['match'], self::MATCHES, true)) {
            $this->problems[] = ['code' => 'invalid-rule', 'message' => "{$where}: match must be one of ".implode(', ', self::MATCHES), 'files' => [$file]];

            return false;
        }
        if ($owners !== [] && !in_array($rule['owner'], $owners, true)) {
            $this->problems[] = ['code' => 'invalid-rule', 'message' => "{$where}: unknown owner \"{$rule['owner']}\"", 'files' => [$file]];

            return false;
        }

        return true;
    }
}
