<?php

declare(strict_types=1);

namespace Vaulter\Inventory;

/**
 * The type-map: who owns every type, file and SchemaBuilder member after the kernel split. The five rule files under
 * tests/Repo/Arch/Fixtures/target-map/rules are the only hand-written input; this class resolves them against the
 * SourceIndex (through Rules), normalises each winner and judges the result. A subject nobody classified, a rule that
 * wins nothing, a contradicting field or a colliding target is a problem, never a silent default.
 *
 * @phpstan-import-type Declaration from SourceIndex
 * @phpstan-import-type Problem from Rules
 * @phpstan-import-type Rule from Rules
 *
 * @phpstan-type Built array{data: array<string, mixed>, problems: list<Problem>}
 */
final class TargetMap
{
    public const FIXTURE = 'tests/Repo/Arch/Fixtures/target-map.json';

    public const RULES_DIR = 'tests/Repo/Arch/Fixtures/target-map/rules';

    /** The commit the P2 inventory is taken at (p2-design#base). */
    public const BASE = '35718fd2';

    public const OWNERS = ['core', 'media', 'drive', 'documents', 'collaboration', 'webhooks', 'azgard', 'filament'];

    public const ACTIONS = ['keep', 'move', 'split', 'replace', 'delete'];

    public const PHASES = ['P3', 'P4', 'P5', 'stay'];

    public const SURFACES = ['Api', 'Spi:Implement', 'Spi:Call', 'Internal', 'undecided'];

    public const API_ATTRIBUTE = 'Vaulter\\Attributes\\Api';

    public const SPI_ATTRIBUTE = 'Vaulter\\Attributes\\Spi';

    public const INTERNAL_ATTRIBUTE = 'Vaulter\\Attributes\\Internal';

    public const SCHEMA_BUILDER = 'Vaulter\\Schema\\SchemaBuilder';

    /** Types whose every method is classified (member rules), and the named methods of other split types. */
    public const MEMBERS_ALL = [self::SCHEMA_BUILDER];

    public const MEMBERS_NAMED = ['Vaulter\\Media\\Cas\\BlobStore' => ['attach', 'detach']];

    public const REPLACEMENT = 'P4: authorized drive listing/lookup API and snapshots (I9)';

    private const SOURCE_ROOTS = ['database', 'config', 'routes'];

    /** @var array<string, string> owner => namespace segment of its package */
    private const OWNER_NAMESPACES = [
        'core' => '', 'media' => 'Media', 'drive' => 'Drive', 'documents' => 'Documents', 'collaboration' => 'Collaboration',
        'webhooks' => 'Webhooks', 'azgard' => 'Azgard', 'filament' => 'Filament',
    ];

    private const ROLES = ['config' => 'config', 'database/migrations' => 'migration', 'database/factories' => 'factory', 'routes' => 'routes'];

    /**
     * @param  list<string>          $files    repo-relative files of packages/*\/{database,config,routes}
     * @param  array<string, string> $psr4     prefix => package
     * @param  array<string, true>   $baseline types of the current PublicApiSnapshot (never Internal without a note)
     * @return Built
     */
    public static function build(SourceIndex $index, Rules $rules, array $files, array $psr4, array $baseline = [], ?string $only = null): array
    {
        return (new self)->compute($index, $rules, $files, $psr4, $baseline, $only);
    }

    /**
     * @param  list<string>          $files
     * @param  array<string, string> $psr4
     * @param  array<string, true>   $baseline
     * @return Built
     */
    private function compute(SourceIndex $index, Rules $rules, array $files, array $psr4, array $baseline, ?string $only): array
    {
        $declarations = [];
        $declared = [];
        foreach ($index->declarations() as $declaration) {
            if (str_contains($declaration['fqcn'], '@anonymous')) {
                continue;
            }
            $declarations[$declaration['fqcn']] ??= $declaration;
            $declared[$declaration['file']] = true;
        }
        ksort($declarations, SORT_STRING);

        $files = array_values(array_filter(array_unique($files), static fn (string $f): bool => !isset($declared[$f])));
        sort($files, SORT_STRING);

        /** @var list<array{string, string}> $members [fqcn, method] */
        $members = [];
        foreach (self::MEMBERS_ALL as $fqcn) {
            foreach ($declarations[$fqcn]['methods'] ?? [] as $method) {
                $members[] = [$fqcn, $method['name']];
            }
        }
        foreach (self::MEMBERS_NAMED as $fqcn => $names) {
            foreach ($names as $name) {
                if (isset($declarations[$fqcn])) {
                    $members[] = [$fqcn, $name];
                }
            }
        }
        $members = array_values(array_unique($members, SORT_REGULAR));
        usort($members, static fn (array $a, array $b): int => $a <=> $b);

        $subjects = [];
        foreach (array_keys($declarations) as $fqcn) {
            $subjects[] = ['kind' => 'type', 'key' => $fqcn];
        }
        foreach ($files as $file) {
            $subjects[] = ['kind' => 'file', 'key' => $file];
        }
        foreach ($members as [$fqcn, $name]) {
            $subjects[] = ['kind' => 'member', 'key' => "{$fqcn}::{$name}"];
        }

        $resolution = $rules->resolve($subjects, $only);
        $this->problems = $resolution['problems'];
        $winners = $resolution['winners'];
        $namespaces = $this->namespaces($psr4);

        $types = [];
        foreach ($declarations as $fqcn => $declaration) {
            $winner = $winners['type:'.$fqcn] ?? null;
            if ($winner !== null) {
                $types[] = $this->type($declaration, $winner, $index, $namespaces, $baseline);
            }
        }

        $memberEntries = [];
        foreach ($members as [$fqcn, $name]) {
            $winner = $winners["member:{$fqcn}::{$name}"] ?? null;
            if ($winner !== null) {
                $memberEntries[] = $this->member($fqcn, $name, $declarations[$fqcn]['package'] ?? 'core', $winner);
            }
        }

        $fileEntries = [];
        foreach ($files as $file) {
            $winner = $winners['file:'.$file] ?? null;
            if ($winner !== null) {
                $fileEntries[] = $this->file($file, $winner);
            }
        }

        $this->collisions($types);

        $owners = [];
        foreach ($types as $entry) {
            $owners[$entry['fqcn']] = $entry['owner'];
        }
        $reads = $this->filamentReads($index, $owners);

        $problems = $this->problems;
        if ($only !== null) {
            $problems = array_values(array_filter($problems, static fn (array $p): bool => in_array($only, $p['files'], true)));
        }

        return [
            'data' => [
                'base' => self::BASE,
                'consumers' => ['filament_model_reads' => $reads],
                'files' => $fileEntries,
                'members' => $memberEntries,
                'owners' => self::OWNERS,
                'types' => $types,
                'version' => 1,
            ],
            'problems' => $problems,
        ];
    }

    /** @var list<Problem> */
    private array $problems = [];

    /**
     * Every PHP file of packages/<p>/{database,config,routes}, repo-relative and sorted.
     *
     * @return list<string>
     */
    public static function sourceFiles(Repo $repo): array
    {
        $files = [];
        foreach ($repo->sourcePackages() as $package) {
            foreach (self::SOURCE_ROOTS as $root) {
                $dir = $repo->path("packages/{$package}/{$root}");
                if (!is_dir($dir)) {
                    continue;
                }
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                        $files[] = substr($file->getPathname(), strlen($repo->root()) + 1);
                    }
                }
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * The types named by the current PublicApiSnapshot files (the pre-P3 public baseline).
     *
     * @return array<string, true>
     */
    public static function baseline(Repo $repo): array
    {
        $types = [];
        foreach (glob($repo->path('packages/*/tests/Arch/__snapshots__/public-api.txt')) ?: [] as $path) {
            foreach (explode("\n", (string) file_get_contents($path)) as $line) {
                if ($line === '' || $line[0] === ' ') {
                    continue;
                }
                $name = explode('::', preg_replace('/^FACADE /', '', $line) ?? $line, 2)[0];
                if (preg_match('/^\\\\?Vaulter(\\\\[A-Za-z0-9_]+)+$/', $name) === 1) {
                    $types[ltrim($name, '\\')] = true;
                }
            }
        }
        ksort($types, SORT_STRING);

        return $types;
    }

    /**
     * The default target of a moved type: `Vaulter\<Rel>` → `Vaulter\<OwnerNs>\<Rel>`, `<Rel>` being relative to the
     * namespace of the package the type lives in. The segment equal to the owner right after the top directory is
     * dropped (`Models\Drive\Node` → `Drive\Models\Node`); a top segment that already is the owner namespace is not doubled.
     */
    public static function defaultTarget(string $fqcn, string $baseNamespace, string $owner): string
    {
        $ownerNs = self::OWNER_NAMESPACES[$owner] ?? ucfirst($owner);
        $rel = str_starts_with($fqcn, $baseNamespace) ? substr($fqcn, strlen($baseNamespace)) : ltrim($fqcn, '\\');
        $segments = explode('\\', $rel);

        if ($ownerNs === '') {
            return 'Vaulter\\'.$rel;
        }
        if (strcasecmp($segments[0], $ownerNs) === 0) {
            array_shift($segments);
        } elseif (count($segments) > 1 && strcasecmp($segments[1], $ownerNs) === 0) {
            array_splice($segments, 1, 1);
        }

        return implode('\\', ['Vaulter', $ownerNs, ...$segments]);
    }

    /**
     * The target-map rendered for people: counts by package, owner, action, phase, surface and the named lists.
     *
     * @param array<string, mixed> $data
     */
    public static function markdown(array $data): string
    {
        /** @var list<array<string, mixed>> $types */
        $types = $data['types'];
        $lines = ['# Type-map (P2.2)', '', sprintf('Generated from `tests/Repo/Arch/Fixtures/target-map.json` (base `%s`); the JSON is the source of truth.', $data['base']), ''];

        $table = static function (string $title, array $headers, array $rows) use (&$lines): void {
            $lines[] = "## {$title}";
            $lines[] = '';
            $lines[] = '| '.implode(' | ', $headers).' |';
            $lines[] = '|'.str_repeat(':--|', count($headers));
            foreach ($rows as $row) {
                $lines[] = '| '.implode(' | ', $row).' |';
            }
            $lines[] = '';
        };

        $count = static function (array $items, callable $key): array {
            $counts = [];
            foreach ($items as $item) {
                $k = $key($item);
                $counts[$k] = ($counts[$k] ?? 0) + 1;
            }
            ksort($counts, SORT_STRING);

            return $counts;
        };

        $table('Types: package → owner × action × phase', ['package', 'owner', 'action', 'phase', 'types'], array_map(
            static fn (string $k, int $n): array => [...explode('|', $k), (string) $n],
            array_keys($rows = $count($types, static fn (array $t): string => implode('|', [$t['package'], $t['owner'], $t['action'], $t['phase']]))),
            array_values($rows),
        ));
        $table('Surface by owner', ['owner', 'surface', 'types'], array_map(
            static fn (string $k, int $n): array => [...explode('|', $k), (string) $n],
            array_keys($rows = $count($types, static fn (array $t): string => $t['owner'].'|'.$t['surface'])),
            array_values($rows),
        ));

        $named = static function (string $title, array $items) use (&$lines): void {
            $lines[] = "## {$title} (".count($items).')';
            $lines[] = '';
            foreach ($items as $item) {
                $lines[] = '- '.$item;
            }
            if ($items === []) {
                $lines[] = '- —';
            }
            $lines[] = '';
        };
        $describe = static fn (array $t): string => sprintf('`%s` → %s%s%s', $t['fqcn'], $t['owner'], $t['target_fqcn'] !== null ? " `{$t['target_fqcn']}`" : '', $t['note'] !== null ? ' — '.$t['note'] : '');

        foreach (['split', 'replace', 'delete'] as $action) {
            $named("Types: {$action}", array_map($describe, array_values(array_filter($types, static fn (array $t): bool => $t['action'] === $action))));
        }
        $named('Types: surface undecided', array_map($describe, array_values(array_filter($types, static fn (array $t): bool => $t['surface'] === 'undecided'))));
        $named('Types: note "open: …" (input for P2.5 open-questions)', array_map($describe, array_values(array_filter($types, static fn (array $t): bool => is_string($t['note']) && str_contains($t['note'], 'open:')))));

        /** @var list<array<string, mixed>> $files */
        $files = $data['files'];
        $table('Files: package → owner × role × action × phase', ['package', 'owner', 'role', 'action', 'phase', 'files'], array_map(
            static fn (string $k, int $n): array => [...explode('|', $k), (string) $n],
            array_keys($rows = $count($files, static fn (array $f): string => implode('|', [$f['package'], $f['owner'], $f['role'], $f['action'], $f['phase']]))),
            array_values($rows),
        ));
        $named('Files: split / open', array_map(static fn (array $f): string => sprintf('`%s` → %s (%s)%s', $f['file'], $f['owner'], $f['action'], $f['note'] !== null ? ' — '.$f['note'] : ''), array_values(array_filter($files, static fn (array $f): bool => $f['action'] !== 'keep' && $f['action'] !== 'move' || (is_string($f['note']) && str_contains($f['note'], 'open:'))))));

        /** @var list<array<string, mixed>> $members */
        $members = $data['members'];
        $table('Members (SchemaBuilder, BlobStore): owner × action × phase', ['owner', 'action', 'phase', 'members'], array_map(
            static fn (string $k, int $n): array => [...explode('|', $k), (string) $n],
            array_keys($rows = $count($members, static fn (array $m): string => implode('|', [$m['owner'], $m['action'], $m['phase']]))),
            array_values($rows),
        ));
        $named('Members', array_map(static fn (array $m): string => sprintf('`%s::%s` → %s, %s, %s%s', $m['fqcn'], $m['member'], $m['owner'], $m['action'], $m['phase'], $m['note'] !== null ? ' — '.$m['note'] : ''), $members));

        /** @var list<array<string, mixed>> $reads */
        $reads = $data['consumers']['filament_model_reads'];
        $models = $count($reads, static fn (array $r): string => $r['model']);
        $named('Filament model reads (models; '.count($reads).' reads)', array_map(static fn (string $m, int $n): string => "`{$m}` × {$n}", array_keys($models), array_values($models)));

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, string> $psr4
     * @return array<string, string> package => its main (shortest) namespace prefix
     */
    private function namespaces(array $psr4): array
    {
        $by = [];
        foreach ($psr4 as $prefix => $package) {
            if (!isset($by[$package]) || strlen($prefix) < strlen($by[$package])) {
                $by[$package] = $prefix;
            }
        }

        return $by;
    }

    /**
     * @param  Declaration                                 $declaration
     * @param  array{id: string, file: string, rule: Rule} $winner
     * @param  array<string, string>                       $namespaces
     * @param  array<string, true>                         $baseline
     * @return array<string, mixed>
     */
    private function type(array $declaration, array $winner, SourceIndex $index, array $namespaces, array $baseline): array
    {
        $rule = $winner['rule'];
        $fqcn = $declaration['fqcn'];
        $package = $declaration['package'] ?? 'core';
        $owner = (string) $rule['owner'];
        [$action, $phase] = $this->actionAndPhase($winner, $package, $owner, "type {$fqcn}");

        $target = null;
        if ($action === 'delete') {
            $target = null;
        } elseif (array_key_exists('target', $rule)) {
            // an explicit target; null states that the replacement has no one-to-one successor type (the note says what replaces it)
            $target = $rule['target'] === null ? null : ltrim((string) $rule['target'], '\\');
        } elseif ($action === 'keep' || $owner === $package) {
            $target = $fqcn;
        } else {
            $target = self::defaultTarget($fqcn, $namespaces[$package] ?? 'Vaulter\\', $owner);
        }

        $note = isset($rule['note']) ? (string) $rule['note'] : null;
        $surface = $this->surface($declaration, "type {$fqcn}", $winner['file']);
        if ($surface === 'Internal' && $note === null && isset($baseline[$fqcn])) {
            $this->problem('baseline-internal', "type {$fqcn} is in the current public API snapshot: Internal needs a note (rule {$winner['id']})", $winner['file']);
        }
        if ($action === 'split' && $note === null) {
            $this->problem('split-without-note', "type {$fqcn}: split needs a note naming the parts (rule {$winner['id']})", $winner['file']);
        }

        return [
            'fqcn' => $fqcn,
            'file' => $declaration['file'],
            'kind' => $declaration['kind'],
            'package' => $package,
            'owner' => $owner,
            'target_fqcn' => $target,
            'surface' => $surface,
            'action' => $action,
            'phase' => $phase,
            'eloquent' => $index->isEloquent($fqcn),
            'rule' => $winner['id'],
            'note' => $note,
        ];
    }

    /**
     * @param  array{id: string, file: string, rule: Rule} $winner
     * @return array<string, mixed>
     */
    private function member(string $fqcn, string $name, string $package, array $winner): array
    {
        $rule = $winner['rule'];
        $owner = (string) $rule['owner'];
        [$action, $phase] = $this->actionAndPhase($winner, $package, $owner, "member {$fqcn}::{$name}");
        $note = isset($rule['note']) ? (string) $rule['note'] : null;
        if ($action === 'split' && $note === null) {
            $this->problem('split-without-note', "member {$fqcn}::{$name}: split needs a note naming the parts (rule {$winner['id']})", $winner['file']);
        }

        return ['fqcn' => $fqcn, 'member' => $name, 'owner' => $owner, 'action' => $action, 'phase' => $phase, 'note' => $note];
    }

    /**
     * @param  array{id: string, file: string, rule: Rule} $winner
     * @return array<string, mixed>
     */
    private function file(string $file, array $winner): array
    {
        $rule = $winner['rule'];
        $package = SourceIndex::packageOfFile($file) ?? 'core';
        $owner = (string) $rule['owner'];
        [$action, $phase] = $this->actionAndPhase($winner, $package, $owner, "file {$file}");
        $note = isset($rule['note']) ? (string) $rule['note'] : null;
        if ($action === 'split' && $note === null) {
            $this->problem('split-without-note', "file {$file}: split needs a note naming the parts (rule {$winner['id']})", $winner['file']);
        }

        return ['file' => $file, 'package' => $package, 'owner' => $owner, 'role' => $this->role($file, $rule), 'action' => $action, 'phase' => $phase, 'note' => $note];
    }

    /** @param  Rule $rule */
    private function role(string $file, array $rule): string
    {
        if (isset($rule['role']) && is_string($rule['role'])) {
            return $rule['role'];
        }
        foreach (self::ROLES as $path => $role) {
            if (preg_match('#^packages/[^/]+/'.preg_quote($path, '#').'(/|$)#', $file) === 1) {
                return $role;
            }
        }

        return 'file';
    }

    /**
     * Fills and validates `action` and `phase`: keep stays in its package (stay|P3), move/split/replace/delete change it.
     *
     * @param  array{id: string, file: string, rule: Rule} $winner
     * @return array{0: string, 1: string}
     */
    private function actionAndPhase(array $winner, string $package, string $owner, string $label): array
    {
        $rule = $winner['rule'];
        $action = isset($rule['action']) ? (string) $rule['action'] : ($owner === $package ? 'keep' : 'move');
        $phase = isset($rule['phase']) ? (string) $rule['phase'] : ($action === 'keep' ? 'stay' : ($owner === 'collaboration' ? 'P5' : 'P4'));
        $where = "{$label} (rule {$winner['id']})";

        if (!in_array($action, self::ACTIONS, true)) {
            $this->problem('invalid-field', "{$where}: action \"{$action}\" is not one of ".implode(', ', self::ACTIONS), $winner['file']);
        }
        if (!in_array($phase, self::PHASES, true)) {
            $this->problem('invalid-field', "{$where}: phase \"{$phase}\" is not one of ".implode(', ', self::PHASES), $winner['file']);
        }
        if ($action === 'keep' && ($owner !== $package || !in_array($phase, ['stay', 'P3'], true))) {
            $this->problem('invalid-field', "{$where}: keep stays in {$package} with phase stay|P3 (owner {$owner}, phase {$phase})", $winner['file']);
        }
        if ($action === 'move' && ($owner === $package || !in_array($phase, ['P4', 'P5'], true))) {
            $this->problem('invalid-field', "{$where}: move leaves {$package} with phase P4|P5 (owner {$owner}, phase {$phase})", $winner['file']);
        }
        if ($action === 'delete' && !isset($rule['note'])) {
            $this->problem('delete-without-note', "{$where}: delete needs a note with the reason and the decision", $winner['file']);
        }

        return [$action, $phase];
    }

    /**
     * The surface a type declares for itself (D27): its one `#[Api]`, `#[Spi(SpiKind::…)]` or `#[Internal]`; `undecided`
     * while it carries none (I13 reports that, the rules no longer decide it).
     *
     * @param Declaration $declaration
     */
    private function surface(array $declaration, string $label, string $file): string
    {
        $found = [];
        foreach ($declaration['attributes'] as $attribute) {
            $found[] = match ($attribute) {
                self::API_ATTRIBUTE => 'Api',
                self::INTERNAL_ATTRIBUTE => 'Internal',
                self::SPI_ATTRIBUTE => 'Spi:'.($declaration['spi_kind'] ?? 'Implement'),
                default => null,
            };
        }
        $found = array_values(array_filter($found));
        if (count($found) > 1) {
            $this->problem('invalid-field', "{$label}: carries more than one surface attribute (".implode(', ', $found).')', $file);
        }

        return $found[0] ?? 'undecided';
    }

    /** @param  list<array<string, mixed>> $types */
    private function collisions(array $types): void
    {
        $by = [];
        foreach ($types as $entry) {
            if ($entry['target_fqcn'] !== null) {
                $by[$entry['target_fqcn']][] = $entry;
            }
        }
        ksort($by, SORT_STRING);
        foreach ($by as $target => $entries) {
            if (count($entries) > 1) {
                $files = array_values(array_unique(array_map(static fn (array $e): string => explode(':', (string) $e['rule'], 2)[0], $entries)));
                $this->problem('target-collision', sprintf('target %s is claimed by %s', $target, implode(', ', array_map(static fn (array $e): string => $e['fqcn'], $entries))), ...$files);
            }
        }
    }

    /**
     * References from packages/filament/src to Eloquent models, deduplicated by file, symbol and model.
     *
     * @param  array<string, string>      $owners fqcn => owner
     * @return list<array<string, mixed>>
     */
    private function filamentReads(SourceIndex $index, array $owners): array
    {
        $reads = [];
        foreach ($index->references() as $reference) {
            if (!in_array($reference['kind'], ['use', 'name'], true) || !str_starts_with($reference['file'], 'packages/filament/src/')) {
                continue;
            }
            $model = ltrim($reference['target'], '\\');
            if (!isset($owners[$model]) || !$index->isEloquent($model)) {
                continue;
            }
            $reads[$reference['file']."\0".$reference['symbol']."\0".$model] = [
                'file' => $reference['file'],
                'symbol' => $reference['symbol'],
                'model' => $model,
                'owner' => $owners[$model],
                'replacement' => self::REPLACEMENT,
            ];
        }
        ksort($reads, SORT_STRING);

        return array_values($reads);
    }

    private function problem(string $code, string $message, string ...$files): void
    {
        $this->problems[] = ['code' => $code, 'message' => $message, 'files' => array_values(array_unique($files))];
    }
}
