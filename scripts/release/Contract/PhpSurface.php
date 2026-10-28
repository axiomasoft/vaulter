<?php

declare(strict_types=1);

namespace Vaulter\Release\Contract;

/**
 * The PHP adapter (D14, knowledge p6-surface#php): compares the `surface.json` of two revisions member by member, keyed by
 * (FQCN, member kind, member name) — never as a set of signature lines, which loses the owner of two identical methods.
 * The members of a root are resolved through its whole graph: parent, traits with their alias/insteadof/visibility
 * adaptations and interfaces, Internal ancestors included; an Internal ancestor is no root, but what a root inherits from
 * it is the root's surface. A Vaulter ancestor outside the graph is REVIEW, never a silent skip.
 *
 * Automatic BREAK: a gone type or member, a root or member turned Internal, a new obligation of an implementer SPI, a new
 * required parameter, a renamed parameter (named arguments), a narrower visibility, new final/abstract/readonly, a changed
 * reference or static mode, a changed enum backing value. A changed type, default, bound, attribute or PHPDoc is REVIEW
 * (variance is not classified). A new enum case, a member of an extensible type: REVIEW. A new type and a new concrete
 * member of a final caller-side type: ADD.
 *
 * @phpstan-import-type ChangeRow from Change
 */
final class PhpSurface
{
    private const ROOTS = ['Api', 'Spi:Implement', 'Spi:Call'];

    private const SURFACES = ['Api', 'Spi:Implement', 'Spi:Call', 'Internal', 'undecided'];

    private const TYPE_KEYS = ['kind', 'surface', 'since', 'modifiers', 'backing', 'extends', 'implements', 'traits', 'adaptations', 'attributes', 'deprecated', 'doc_internal', 'doc', 'members'];

    private const MEMBER_KEYS = [
        'method' => ['kind', 'name', 'visibility', 'internal', 'static', 'abstract', 'final', 'by_ref', 'params', 'return', 'attributes', 'deprecated', 'doc_internal', 'doc'],
        'property' => ['kind', 'name', 'visibility', 'set_visibility', 'internal', 'promoted', 'static', 'readonly', 'abstract', 'final', 'type', 'default', 'hooks', 'attributes', 'deprecated', 'doc_internal', 'doc'],
        'constant' => ['kind', 'name', 'visibility', 'internal', 'final', 'type', 'value', 'attributes', 'deprecated', 'doc_internal', 'doc'],
        'case' => ['kind', 'name', 'value', 'attributes', 'deprecated', 'doc'],
    ];

    private const PARAM_KEYS = ['name', 'type', 'default', 'variadic', 'by_ref', 'promoted', 'attributes'];

    /** The surface attributes are compared as `surface`/`since`, not again as attributes. */
    private const SURFACE_ATTRIBUTE = '/^Vaulter\\\\Attributes\\\\(?:Api|Spi|Internal)\b/';

    /**
     * The types of a revision: the decoded `surface.json` of every package, validated.
     *
     * @param  array<string, string>               $documents path => JSON
     * @return array<string, array<string, mixed>> FQCN => structure
     */
    public static function load(array $documents): array
    {
        $types = [];
        $folded = [];
        foreach ($documents as $path => $json) {
            $data = SnapshotManifest::decode($json, $path);
            SnapshotManifest::keys($data, ['format', 'version', 'package', 'types'], ['format', 'version', 'package', 'types'], $path);
            if ($data['format'] !== 'vaulter.surface' || $data['version'] !== 1) {
                throw new ContractError("{$path}: unknown surface format or version");
            }
            if (!is_array($data['types'])) {
                throw new ContractError("{$path}: types must be a map");
            }
            foreach ($data['types'] as $fqcn => $type) {
                $fqcn = (string) $fqcn;
                $lower = strtolower($fqcn);
                if (isset($folded[$lower])) {
                    throw new ContractError("{$path}: duplicate type {$fqcn}");
                }
                $folded[$lower] = true;
                $types[$fqcn] = self::validType($type, "{$path}: {$fqcn}");
            }
        }
        ksort($types, SORT_STRING);

        return $types;
    }

    /**
     * The FQCNs one package document declares, lower-cased: the roots a per-package surface reports on, while its members are
     * resolved in the graph of every package of the revision (an ancestor may live in another package).
     *
     * @param  array<string, string> $documents path => JSON
     * @return list<string>
     */
    public static function declared(array $documents): array
    {
        $names = [];
        foreach ($documents as $path => $json) {
            $data = SnapshotManifest::decode($json, $path);
            foreach (is_array($data['types'] ?? null) ? array_keys($data['types']) : [] as $fqcn) {
                $names[] = strtolower((string) $fqcn);
            }
        }
        sort($names, SORT_STRING);

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, array<string, mixed>> $before
     * @param  array<string, array<string, mixed>> $after
     * @param  list<string>|null                   $only   the lower-cased roots to report on (both sides of one package); null: all
     * @return list<ChangeRow>
     */
    public static function compare(string $surface, array $before, array $after, ?array $only = null): array
    {
        $changes = [];
        $afterFolded = [];
        foreach ($after as $fqcn => $type) {
            $afterFolded[strtolower($fqcn)] = $fqcn;
        }
        $reported = static fn (string $fqcn): bool => $only === null || in_array(strtolower($fqcn), $only, true);
        $beforeRoots = [];
        foreach ($before as $fqcn => $type) {
            if (!in_array($type['surface'], self::ROOTS, true) || !$reported($fqcn)) {
                continue;
            }
            $beforeRoots[strtolower($fqcn)] = true;
            $name = $afterFolded[strtolower($fqcn)] ?? null;
            if ($name === null) {
                $changes[] = Change::make($surface, $fqcn, 'type-removed', Change::BREAK, self::view($type), null, "{$fqcn} is gone.");

                continue;
            }
            if (!in_array($after[$name]['surface'], self::ROOTS, true)) {
                $changes[] = Change::make($surface, $fqcn, 'type-hidden', Change::BREAK, self::view($type), self::view($after[$name]), "{$fqcn} was {$type['surface']} and is now {$after[$name]['surface']}: no longer a contract root.");

                continue;
            }
            array_push($changes, ...self::compareType($surface, $fqcn, $before, $after, $name));
        }
        foreach ($after as $fqcn => $type) {
            if (in_array($type['surface'], self::ROOTS, true) && $reported($fqcn) && !isset($beforeRoots[strtolower($fqcn)])) {
                $changes[] = Change::make($surface, $fqcn, 'type-added', Change::ADD, null, self::view($type), "new {$type['surface']} type {$fqcn}.");
            }
        }

        return Change::sorted($changes);
    }

    /**
     * The members a type exposes through its graph, each with the type that declares it (`from`), and the names of its
     * ancestors: resolved in the graph, or unresolved (outside the sources).
     *
     * @param  array<string, array<string, mixed>>                                                                 $types
     * @return array{members: array<string, array<string, mixed>>, ancestors: list<string>, unresolved: list<string>}
     */
    public static function resolve(array $types, string $fqcn): array
    {
        $ancestors = [];
        $unresolved = [];
        $members = self::membersOf($types, $fqcn, [], $ancestors, $unresolved);
        ksort($members, SORT_STRING);
        $ancestors = array_values(array_unique($ancestors));
        $unresolved = array_values(array_unique($unresolved));
        sort($ancestors, SORT_STRING);
        sort($unresolved, SORT_STRING);

        return ['members' => $members, 'ancestors' => $ancestors, 'unresolved' => $unresolved];
    }

    /**
     * @param  array<string, array<string, mixed>> $types
     * @param  array<string, true>                 $path
     * @param  list<string>                        $ancestors
     * @param  list<string>                        $unresolved
     * @return array<string, array<string, mixed>>
     */
    private static function membersOf(array $types, string $fqcn, array $path, array &$ancestors, array &$unresolved): array
    {
        if (isset($path[strtolower($fqcn)])) {
            throw new ContractError("inheritance cycle at {$fqcn}");
        }
        $path[strtolower($fqcn)] = true;
        $type = $types[$fqcn];
        $inherit = static function (string $name) use ($types, $path, &$ancestors, &$unresolved): ?array {
            $ancestors[] = $name;
            if (!isset($types[$name])) {
                $unresolved[] = $name;

                return null;
            }

            return self::membersOf($types, $name, $path, $ancestors, $unresolved);
        };

        $members = [];
        // The parent first, then the traits (which override it), then the own members (which override both).
        foreach ($type['kind'] === 'class' ? $type['extends'] : [] as $parent) {
            foreach ($inherit($parent) ?? [] as $key => $member) {
                if (($member['visibility'] ?? 'public') !== 'private') {
                    $members[$key] = $member;
                }
            }
        }
        $fromTraits = [];
        foreach ($type['traits'] as $trait) {
            foreach ($inherit($trait) ?? [] as $key => $member) {
                $member['trait'] = $trait;
                $fromTraits[$key][] = $member;
            }
        }
        foreach ($type['adaptations'] as $adaptation) {
            $key = 'method:'.$adaptation['method'];
            if ($adaptation['insteadof'] !== []) {
                $fromTraits[$key] = array_values(array_filter($fromTraits[$key] ?? [], static fn (array $m): bool => !in_array($m['trait'], $adaptation['insteadof'], true)));
            }
        }
        foreach ($fromTraits as $key => $candidates) {
            if (count($candidates) > 1 && !isset($type['members'][$key])) {
                throw new ContractError("{$fqcn}: unresolved trait conflict on {$key}");
            }
            $members[$key] = $candidates[0] ?? null;
        }
        $members = array_filter($members);
        foreach ($type['adaptations'] as $adaptation) {
            if ($adaptation['insteadof'] !== []) {
                continue;
            }
            $source = null;
            foreach ($fromTraits['method:'.$adaptation['method']] ?? [] as $candidate) {
                if ($adaptation['trait'] === null || $candidate['trait'] === $adaptation['trait']) {
                    $source = $candidate;
                }
            }
            if ($source === null) {
                // An adaptation of a method of an unresolved trait: its result is unknown.
                $unresolved[] = ($adaptation['trait'] ?? $fqcn).'::'.$adaptation['method'];

                continue;
            }
            $adapted = $source;
            $adaptation['visibility'] !== null && $adapted['visibility'] = $adaptation['visibility'];
            if ($adaptation['alias'] !== null) {
                $adapted['name'] = $adaptation['alias'];
                $members['method:'.strtolower($adaptation['alias'])] = $adapted;
            } else {
                $members['method:'.$adaptation['method']] = $adapted;
            }
        }
        foreach ($type['members'] as $key => $member) {
            $member['from'] = $fqcn;
            $members[$key] = $member;
        }
        // Interfaces add their members where the type has none (the obligations of an interface or an abstract class).
        $interfaces = $type['kind'] === 'interface' ? $type['extends'] : $type['implements'];
        foreach ($interfaces as $interface) {
            foreach ($inherit($interface) ?? [] as $key => $member) {
                $members[$key] ??= $member;
            }
        }
        foreach ($members as $key => $member) {
            $members[$key]['from'] ??= $fqcn;
            unset($members[$key]['trait']);
            if (($member['visibility'] ?? 'public') === 'private') {
                unset($members[$key]);
            }
        }

        return $members;
    }

    /**
     * @param  array<string, array<string, mixed>> $before
     * @param  array<string, array<string, mixed>> $after
     * @return list<ChangeRow>
     */
    private static function compareType(string $surface, string $fqcn, array $before, array $after, string $afterName): array
    {
        $b = $before[$fqcn];
        $a = $after[$afterName];
        $changes = [];
        $add = static function (string $subject, string $rule, string $classification, mixed $from, mixed $to, string $why) use (&$changes, $surface): void {
            $changes[] = Change::make($surface, $subject, $rule, $classification, $from, $to, $why);
        };

        if ($b['kind'] !== $a['kind']) {
            $add($fqcn, 'type-kind', Change::BREAK, $b['kind'], $a['kind'], "{$fqcn} changed from {$b['kind']} to {$a['kind']}.");
        }
        if ($b['surface'] !== $a['surface']) {
            $add($fqcn, 'surface-kind', Change::REVIEW, $b['surface'], $a['surface'], "{$fqcn} changed from {$b['surface']} to {$a['surface']}: callers and implementers have different obligations.");
        }
        foreach (['final', 'abstract', 'readonly'] as $modifier) {
            if ($b['modifiers'][$modifier] !== $a['modifiers'][$modifier]) {
                $a['modifiers'][$modifier]
                    ? $add($fqcn, $modifier.'-added', Change::BREAK, false, true, "{$fqcn} became {$modifier}: extending or instantiating code breaks.")
                    : $add($fqcn, $modifier.'-removed', Change::REVIEW, true, false, "{$fqcn} is no longer {$modifier}.");
            }
        }
        if ($b['backing'] !== $a['backing']) {
            $add($fqcn, 'enum-backing', Change::BREAK, $b['backing'], $a['backing'], "{$fqcn}: the enum backing type changed.");
        }
        if ($b['since'] !== $a['since']) {
            $add($fqcn, 'since', Change::REVIEW, $b['since'], $a['since'], "{$fqcn}: the declared since version changed.");
        }
        self::common($fqcn, $b, $a, $add, true);

        $rb = self::resolve($before, $fqcn);
        $ra = self::resolve($after, $afterName);
        $removed = array_values(array_diff($rb['ancestors'], $ra['ancestors']));
        $added = array_values(array_diff($ra['ancestors'], $rb['ancestors']));
        $removed !== [] && $add($fqcn, 'ancestor-removed', Change::BREAK, $rb['ancestors'], $ra['ancestors'], "{$fqcn} no longer extends/implements/uses ".implode(', ', $removed).': instanceof and type checks break.');
        $added !== [] && $add($fqcn, 'ancestor-added', Change::REVIEW, $rb['ancestors'], $ra['ancestors'], "{$fqcn} now also extends/implements/uses ".implode(', ', $added).': new inherited obligations or collisions.');
        $unknown = array_values(array_filter($ra['unresolved'], static fn (string $n): bool => str_starts_with($n, 'Vaulter\\')));
        $unknown !== [] && $add($fqcn, 'ancestor-unresolved', Change::REVIEW, $rb['unresolved'], $ra['unresolved'], "{$fqcn}: the ancestors ".implode(', ', $unknown).' are not in the graph; what they give the type cannot be compared.');

        $extensible = !($a['modifiers']['final'] || $a['kind'] === 'enum');
        $implement = $a['surface'] === 'Spi:Implement';
        foreach ($rb['members'] as $key => $member) {
            if ($member['internal'] ?? false) {
                continue;
            }
            $subject = self::subject($fqcn, $member);
            $next = $ra['members'][$key] ?? null;
            if ($next === null) {
                $add($subject, 'member-removed', Change::BREAK, self::view($member), null, "{$subject} is gone.");
            } elseif ($next['internal'] ?? false) {
                $add($subject, 'member-internal', Change::BREAK, self::view($member), self::view($next), "{$subject} is now #[Internal]: no longer part of the surface.");
            } else {
                array_push($changes, ...self::compareMember($surface, $subject, $member, $next, $extensible, $implement));
            }
        }
        foreach ($ra['members'] as $key => $member) {
            $previous = $rb['members'][$key] ?? null;
            if (($member['internal'] ?? false) || ($previous !== null && !($previous['internal'] ?? false))) {
                continue;
            }
            $subject = self::subject($fqcn, $member);
            [$rule, $classification, $why] = match (true) {
                $member['kind'] === 'case' => ['enum-case-added', Change::REVIEW, 'a new enum case breaks exhaustive match of the callers.'],
                $member['kind'] === 'method' && ($member['abstract'] || $a['kind'] === 'interface') && $implement => ['spi-obligation-added', Change::BREAK, 'a new abstract method is a new obligation of every implementer of the SPI.'],
                $member['kind'] === 'method' && ($member['abstract'] || $a['kind'] === 'interface') => ['abstract-method-added', Change::REVIEW, 'a new abstract method binds anyone who implements or extends the type.'],
                $extensible || $implement => ['member-added-extensible', Change::REVIEW, 'a new member of an extensible type or a trait can collide with a member of a subclass or implementer.'],
                default => ['member-added', Change::ADD, 'a new member of a final caller-side type.'],
            };
            $add($subject, $rule, $classification, null, self::view($member), "{$subject}: {$why}");
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed> $b
     * @param  array<string, mixed> $a
     * @return list<ChangeRow>
     */
    private static function compareMember(string $surface, string $subject, array $b, array $a, bool $extensible, bool $implement): array
    {
        $changes = [];
        $add = static function (string $subject, string $rule, string $classification, mixed $from, mixed $to, string $why) use (&$changes, $surface): void {
            $changes[] = Change::make($surface, $subject, $rule, $classification, $from, $to, $why);
        };
        $rank = ['private' => 0, 'protected' => 1, 'public' => 2];
        if (isset($b['visibility']) && $b['visibility'] !== $a['visibility']) {
            $rank[$a['visibility']] < $rank[$b['visibility']]
                ? $add($subject, 'visibility-reduced', Change::BREAK, $b['visibility'], $a['visibility'], "{$subject}: {$b['visibility']} became {$a['visibility']}.")
                : $add($subject, 'visibility-widened', Change::REVIEW, $b['visibility'], $a['visibility'], "{$subject}: {$b['visibility']} became {$a['visibility']}; a subclass override with the old visibility no longer compiles.");
        }
        foreach (['static' => Change::BREAK, 'by_ref' => Change::BREAK] as $flag => $classification) {
            if (array_key_exists($flag, $b) && $b[$flag] !== $a[$flag]) {
                $add($subject, $flag === 'by_ref' ? 'return-by-ref' : 'static', $classification, $b[$flag], $a[$flag], "{$subject}: the ".($flag === 'by_ref' ? 'returns-by-reference' : 'static')." mode changed.");
            }
        }
        foreach (['final', 'abstract', 'readonly'] as $flag) {
            if (array_key_exists($flag, $b) && $b[$flag] !== $a[$flag]) {
                $a[$flag] && ($extensible || $flag !== 'final')
                    ? $add($subject, $flag.'-added', Change::BREAK, false, true, "{$subject} became {$flag}.")
                    : $add($subject, $flag.($a[$flag] ? '-added' : '-removed'), Change::REVIEW, $b[$flag], $a[$flag], "{$subject}: {$flag} changed.");
            }
        }
        if ($b['kind'] === 'case' && $b['value'] !== $a['value']) {
            $add($subject, 'enum-value', Change::BREAK, $b['value'], $a['value'], "{$subject}: the backing value changed; stored and serialized values break.");
        }
        if ($b['kind'] === 'constant' && $b['value'] !== $a['value']) {
            $add($subject, 'constant-value', Change::REVIEW, $b['value'], $a['value'], "{$subject}: the value changed.");
        }
        foreach (['type' => 'type', 'return' => 'return-type', 'default' => 'default', 'set_visibility' => 'set-visibility', 'hooks' => 'property-hooks'] as $field => $rule) {
            if (array_key_exists($field, $b) && $b[$field] !== $a[$field]) {
                $add($subject, $rule, Change::REVIEW, $b[$field], $a[$field], "{$subject}: the {$field} changed (variance is not classified automatically).");
            }
        }
        if ($b['kind'] === 'method') {
            array_push($changes, ...self::compareParams($surface, $subject, $b['params'], $a['params'], !$extensible && !$implement && !$a['abstract']));
        }
        self::common($subject, $b, $a, $add, false);

        return $changes;
    }

    /**
     * @param  list<array<string, mixed>> $b
     * @param  list<array<string, mixed>> $a
     * @return list<ChangeRow>
     */
    private static function compareParams(string $surface, string $method, array $b, array $a, bool $callerOnly): array
    {
        $changes = [];
        $base = substr($method, 0, -2);
        for ($i = 0, $n = max(count($b), count($a)); $i < $n; $i++) {
            $old = $b[$i] ?? null;
            $new = $a[$i] ?? null;
            $subject = $base.'($'.($old ?? $new)['name'].')';
            $change = static function (string $rule, string $classification, string $why) use (&$changes, $surface, $subject, $old, $new): void {
                $changes[] = Change::make($surface, $subject, $rule, $classification, $old, $new, "{$subject}: {$why}");
            };
            if ($new === null) {
                $change('parameter-removed', Change::BREAK, 'the parameter is gone; named and positional callers and overriding implementers break.');

                continue;
            }
            if ($old === null) {
                $optional = $new['default'] !== null || $new['variadic'];
                match (true) {
                    !$optional => $change('required-parameter-added', Change::BREAK, 'a new required parameter breaks every existing call.'),
                    $callerOnly => $change('optional-parameter-added', Change::ADD, 'a trailing optional parameter of a final caller-only method.'),
                    default => $change('optional-parameter-added', Change::REVIEW, 'a new optional parameter breaks overriding implementers and subclasses.'),
                };

                continue;
            }
            if ($old['name'] !== $new['name']) {
                $change('parameter-renamed', Change::BREAK, "renamed to \${$new['name']}; named arguments are part of the contract.");
            }
            if ($old['by_ref'] !== $new['by_ref']) {
                $change('parameter-by-ref', Change::BREAK, 'the by-reference mode changed.');
            }
            if ($old['variadic'] !== $new['variadic']) {
                $change('parameter-variadic', Change::BREAK, 'the variadic mode changed.');
            }
            if ($old['default'] !== null && $new['default'] === null && !$new['variadic']) {
                $change('parameter-now-required', Change::BREAK, 'an optional parameter became required.');
            } elseif ($old['default'] !== $new['default']) {
                $change('parameter-default', Change::REVIEW, 'the default changed.');
            }
            if ($old['type'] !== $new['type']) {
                $change('parameter-type', Change::REVIEW, 'the type changed (variance is not classified automatically).');
            }
            if ($old['attributes'] !== $new['attributes']) {
                $change('parameter-attributes', Change::REVIEW, 'the attributes changed.');
            }
        }

        return $changes;
    }

    /**
     * Attributes, deprecation, `@internal` and the contract PHPDoc of a type or member.
     *
     * @param array<string, mixed>                                            $b
     * @param array<string, mixed>                                            $a
     * @param callable(string, string, string, mixed, mixed, string): void    $add
     */
    private static function common(string $subject, array $b, array $a, callable $add, bool $type): void
    {
        $attributes = static fn (array $list): array => array_values(array_filter($list, static fn (string $a): bool => !$type || preg_match(self::SURFACE_ATTRIBUTE, $a) !== 1));
        if ($attributes($b['attributes']) !== $attributes($a['attributes'])) {
            $add($subject, 'attributes', Change::REVIEW, $b['attributes'], $a['attributes'], "{$subject}: the attributes changed.");
        }
        if ($b['deprecated'] !== $a['deprecated']) {
            $a['deprecated']
                ? $add($subject, 'deprecated', Change::ADD, false, true, "{$subject} is now deprecated.")
                : $add($subject, 'undeprecated', Change::REVIEW, true, false, "{$subject} is no longer deprecated.");
        }
        if (($b['doc_internal'] ?? false) !== ($a['doc_internal'] ?? false)) {
            $add($subject, 'doc-internal', Change::REVIEW, $b['doc_internal'] ?? false, $a['doc_internal'] ?? false, "{$subject}: the @internal tag changed (it is not the #[Internal] attribute).");
        }
        if ($b['doc'] !== $a['doc']) {
            $add($subject, 'phpdoc', Change::REVIEW, $b['doc'], $a['doc'], "{$subject}: the contract PHPDoc (types, generics, throws) changed; it is never proven automatically.");
        }
    }

    /** @param array<string, mixed> $member */
    private static function subject(string $fqcn, array $member): string
    {
        return $fqcn.'::'.match ($member['kind']) {
            'method' => $member['name'].'()',
            'property' => '$'.$member['name'],
            default => $member['name'],
        };
    }

    /**
     * A structure as it is hashed: without the bookkeeping of the resolver.
     *
     * @param  array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function view(array $value): array
    {
        unset($value['from'], $value['trait']);

        return $value;
    }

    /** @return array<string, mixed> */
    private static function validType(mixed $type, string $where): array
    {
        SnapshotManifest::keys($type, self::TYPE_KEYS, self::TYPE_KEYS, $where);
        if (!in_array($type['kind'], ['class', 'interface', 'trait', 'enum'], true) || !in_array($type['surface'], self::SURFACES, true)) {
            throw new ContractError("{$where}: unknown kind or surface");
        }
        SnapshotManifest::keys($type['modifiers'], ['abstract', 'final', 'readonly'], ['abstract', 'final', 'readonly'], "{$where} modifiers");
        foreach ($type['adaptations'] as $i => $adaptation) {
            SnapshotManifest::keys($adaptation, ['trait', 'method', 'alias', 'visibility', 'insteadof'], ['trait', 'method', 'alias', 'visibility', 'insteadof'], "{$where} adaptation #{$i}");
        }
        if (!is_array($type['members'])) {
            throw new ContractError("{$where}: members must be a map");
        }
        foreach ($type['members'] as $key => $member) {
            $kind = is_array($member) ? ($member['kind'] ?? null) : null;
            if (!is_string($kind) || !isset(self::MEMBER_KEYS[$kind]) || !is_string($member['name'] ?? null)) {
                throw new ContractError("{$where}: unknown member {$key}");
            }
            $expected = $kind.':'.($kind === 'method' ? strtolower($member['name']) : $member['name']);
            if ($key !== $expected) {
                throw new ContractError("{$where}: member key {$key} does not match {$expected}");
            }
            if (($member['internal'] ?? false) === true) {
                SnapshotManifest::keys($member, ['kind', 'name', 'visibility', 'internal'], ['kind', 'name', 'visibility', 'internal'], "{$where} {$key}");

                continue;
            }
            SnapshotManifest::keys($member, self::MEMBER_KEYS[$kind], self::MEMBER_KEYS[$kind], "{$where} {$key}");
            foreach ($kind === 'method' ? $member['params'] : [] as $i => $param) {
                SnapshotManifest::keys($param, self::PARAM_KEYS, self::PARAM_KEYS, "{$where} {$key} parameter #{$i}");
            }
        }

        return $type;
    }
}
