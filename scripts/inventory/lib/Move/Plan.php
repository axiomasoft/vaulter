<?php

declare(strict_types=1);

namespace Vaulter\Inventory\Move;

use Vaulter\Inventory\Repo;

/**
 * The plan of a move: which types of the target-map move, from which file to which file. A pure function of the
 * target-map, the options and the tree; it changes nothing. A conflict is found here, before anything is written.
 *
 * @phpstan-type Move array{old: string, new: string, file_from: string, file_to: string, done: bool}
 * @phpstan-type Options array{owner: string, rule_file: ?string, prefixes: list<string>, excluded: list<string>, allow_empty?: bool}
 */
final class Plan
{
    public const TARGET_MAP = 'tests/Repo/Arch/Fixtures/target-map.json';

    public const OWNERS = ['drive', 'media', 'webhooks', 'documents'];

    /** The phase whose target-map moves an owner receives. */
    public static function phaseOf(string $owner): string
    {
        return 'P4';
    }

    /**
     * @param  Options    $options
     * @return list<Move>
     */
    public static function select(Repo $repo, array $options): array
    {
        if (!in_array($options['owner'], self::OWNERS, true)) {
            throw new MoveUsage('--owner must be one of '.implode(', ', self::OWNERS));
        }
        /** @var list<array<string, mixed>> $types */
        $types = $repo->readJson(self::TARGET_MAP)['types'] ?? [];

        foreach ($options['prefixes'] as $prefix) {
            foreach ($types as $type) {
                if ($type['fqcn'] === $prefix && in_array($type['action'], ['replace', 'split'], true)) {
                    throw new MoveUsage("{$prefix} is a {$type['action']} type: the tool moves only action=move types");
                }
            }
        }

        $moves = [];
        $byFile = [];
        foreach ($types as $type) {
            $byFile[(string) $type['file']][] = $type;
            if (!self::selected($type, $options)) {
                continue;
            }
            [$prefix, $dir] = self::root($repo, $options['owner'], (string) $type['target_fqcn']);
            $moves[(string) $type['fqcn']] = [
                'old' => (string) $type['fqcn'],
                'new' => (string) $type['target_fqcn'],
                'file_from' => (string) $type['file'],
                'file_to' => $dir.str_replace('\\', '/', substr((string) $type['target_fqcn'], strlen($prefix))).'.php',
                'done' => false,
            ];
        }
        if ($moves === [] && !($options['allow_empty'] ?? false)) {
            throw new MoveUsage('the selection is empty: no type with phase='.self::phaseOf($options['owner']).', action=move, owner='.$options['owner'].($options['rule_file'] !== null ? ', rule '.$options['rule_file'] : '').' matches');
        }
        ksort($moves, SORT_STRING);

        return self::check($repo, array_values($moves), $byFile);
    }

    /**
     * Every type of the target-map (old FQCN): what the code base declares, to tell a class from a template in a docblock.
     *
     * @return array<string, true>
     */
    public static function known(Repo $repo): array
    {
        $known = [];
        foreach ($repo->readJson(self::TARGET_MAP)['types'] ?? [] as $type) {
            $known[(string) $type['fqcn']] = true;
        }

        return $known;
    }

    /**
     * @param array<string, mixed> $type
     * @param Options              $options
     */
    private static function selected(array $type, array $options): bool
    {
        if (($type['phase'] ?? null) !== self::phaseOf($options['owner']) || ($type['action'] ?? null) !== 'move' || ($type['owner'] ?? null) !== $options['owner']) {
            return false;
        }
        if ($options['rule_file'] !== null && explode(':', (string) ($type['rule'] ?? ''), 2)[0] !== $options['rule_file']) {
            return false;
        }
        if (in_array($type['fqcn'], $options['excluded'], true)) {
            return false;
        }
        if ($options['prefixes'] === []) {
            return true;
        }
        foreach ($options['prefixes'] as $prefix) {
            if (str_starts_with((string) $type['fqcn'], $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The PSR-4 root of the owner that holds a FQCN: [namespace prefix, repo-relative directory with a trailing slash].
     * A package that does not exist yet has no composer.json: its root is the packages.json entry, `src/` by default.
     *
     * @return array{string, string}
     */
    public static function root(Repo $repo, string $owner, string $fqcn): array
    {
        $package = $repo->packages()[$owner] ?? throw new MoveUsage("packages.json has no package [{$owner}]");
        $directory = rtrim((string) ($package['dir'] ?? "packages/{$owner}"), '/');
        $psr4 = [];
        if ($repo->exists("{$directory}/composer.json")) {
            /** @var array<string, string|list<string>> $declared */
            $declared = $repo->readJson("{$directory}/composer.json")['autoload']['psr-4'] ?? [];
            foreach ($declared as $prefix => $path) {
                $psr4[$prefix] = is_array($path) ? (string) $path[0] : $path;
            }
        } else {
            foreach ((array) ($package['psr4'] ?? []) as $prefix => $path) {
                $psr4[(string) $prefix] = is_string($path) ? $path : 'src/';
            }
        }
        uksort($psr4, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($psr4 as $prefix => $path) {
            if (str_starts_with($fqcn, $prefix)) {
                return [$prefix, $directory.'/'.trim($path, '/').'/'];
            }
        }

        throw new MoveUsage("{$fqcn} is outside the PSR-4 roots of the package [{$owner}]");
    }

    /**
     * @param  list<Move>                                $moves
     * @param  array<string, list<array<string, mixed>>> $byFile every target-map type of a file
     * @return list<Move>
     */
    private static function check(Repo $repo, array $moves, array $byFile): array
    {
        $selected = array_column($moves, 'old');
        $targets = [];
        $names = [];
        foreach ($moves as $i => $move) {
            $from = $repo->exists($move['file_from']);
            $to = $repo->exists($move['file_to']);
            if (!$from && !$to) {
                throw new MoveUsage("{$move['old']}: neither {$move['file_from']} nor {$move['file_to']} exists");
            }
            if ($from && $to && $move['file_from'] !== $move['file_to']) {
                throw new MoveUsage("{$move['old']}: {$move['file_to']} already exists");
            }
            $moves[$i]['done'] = !$from;
            if (isset($targets[$move['file_to']]) && $targets[$move['file_to']] !== $move['file_from']) {
                throw new MoveUsage("{$move['file_to']} is the target of two files: {$targets[$move['file_to']]} and {$move['file_from']}");
            }
            $targets[$move['file_to']] = $move['file_from'];
            if (isset($names[$move['new']])) {
                throw new MoveUsage("{$move['new']} is the target of two types");
            }
            $names[$move['new']] = true;
            foreach ($byFile[$move['file_from']] ?? [] as $sibling) {
                if (!in_array($sibling['fqcn'], $selected, true)) {
                    throw new MoveUsage("{$move['file_from']} also declares {$sibling['fqcn']}, which is not selected: a file moves whole");
                }
            }
        }
        $perFile = [];
        foreach ($moves as $move) {
            $perFile[$move['file_from']][$move['file_to']] = true;
        }
        foreach ($perFile as $from => $to) {
            if (count($to) > 1) {
                throw new MoveUsage("{$from} would move to several files: ".implode(', ', array_keys($to)));
            }
        }

        return $moves;
    }
}
