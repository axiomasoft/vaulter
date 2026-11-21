<?php

declare(strict_types=1);

/*
 * PHP mirror of replace-exact.mjs on stored Tiptap JSON (decoded with assoc=true), the form the
 * closed adapter ReplaceExact/TextVerifier (plan/spec/implementation/ai.md, 3.3a/3.3d) handles
 * inside AtomicDocumentMutation. Stdlib + mbstring only (PHP 8.4).
 *
 * Usage: php replace_exact.php vectors.json  → JSON results on stdout (compared with Node by
 * plan/checks/sdk/run.sh). Exit code 0 only if every vector matches its expected outcome.
 */

final class ReplaceExactError extends RuntimeException
{
    public function __construct(public readonly string $wireCode, public readonly string $reason)
    {
        parent::__construct("$wireCode: $reason");
    }
}

final class ReplaceExact
{
    public const LEAF = "\u{FFFC}";
    private const ADDRESSABLE = ['paragraph', 'heading'];

    /** Mirrors Fragment.fromArray: drop empty text, join adjacent text with the same mark set. */
    public static function normalize(array $node): array
    {
        if (!isset($node['content'])) {
            return $node;
        }
        $content = [];
        foreach ($node['content'] as $child) {
            $child = self::normalize($child);
            if (($child['type'] ?? null) === 'text') {
                if (($child['text'] ?? '') === '') {
                    continue;
                }
                if (empty($child['marks'])) {
                    unset($child['marks']);
                }
                $last = array_key_last($content);
                if ($last !== null && $content[$last]['type'] === 'text'
                    && self::markKey($content[$last]['marks'] ?? []) === self::markKey($child['marks'] ?? [])) {
                    $content[$last]['text'] .= $child['text'];
                    continue;
                }
            }
            $content[] = $child;
        }
        $node['content'] = $content;
        if ($content === []) {
            unset($node['content']);
        }

        return $node;
    }

    /** Order-insensitive identity of a mark set including attributes. */
    public static function markKey(array $marks): string
    {
        $keys = array_map(static fn (array $m): string => json_encode(self::sortKeys($m), JSON_THROW_ON_ERROR), $marks);
        sort($keys, SORT_STRING);

        return implode("\n", $keys);
    }

    private static function sortKeys(mixed $v): mixed
    {
        if (!is_array($v) || array_is_list($v)) {
            return is_array($v) ? array_map(self::sortKeys(...), $v) : $v;
        }
        ksort($v, SORT_STRING);

        return array_map(self::sortKeys(...), $v);
    }

    public static function utf16Length(string $s): int
    {
        return intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** Text blocks in document order, also inside lists/quotes, like Node.descendants. */
    private static function textBlocks(array $node, array $path, array &$out): void
    {
        foreach ($node['content'] ?? [] as $i => $child) {
            $isTextblock = $child['type'] !== 'text' && (($child['content'][0]['type'] ?? 'text') === 'text'
                || in_array($child['type'], self::ADDRESSABLE, true) || array_key_exists('blockId', $child['attrs'] ?? []));
            if ($child['type'] === 'text' || !isset($child['content']) && !$isTextblock) {
                continue;
            }
            if ($isTextblock && !self::hasBlockChildren($child)) {
                $out[] = [[...$path, $i], $child];
            } else {
                self::textBlocks($child, [...$path, $i], $out);
            }
        }
    }

    private static function hasBlockChildren(array $node): bool
    {
        foreach ($node['content'] ?? [] as $child) {
            if (isset($child['content'])) {
                return true;
            }
        }

        return false;
    }

    public static function setAt(array $doc, array $path, array $block): array
    {
        $i = array_shift($path);
        $doc['content'][$i] = $path === [] ? $block : self::setAt($doc['content'][$i], $path, $block);

        return $doc;
    }

    /** @return array{0:list<int>,1:array} path of content indexes and the block */
    public static function findBlock(array $doc, string $blockId): array
    {
        $blocks = [];
        self::textBlocks($doc, [], $blocks);
        $found = array_values(array_filter($blocks, static fn (array $b): bool => ($b[1]['attrs']['blockId'] ?? null) === $blockId));
        if ($found === []) {
            throw new ReplaceExactError('precondition_failed', 'block_missing');
        }
        if (count($found) > 1) {
            throw new ReplaceExactError('unsupported_content', 'duplicate_block_id');
        }
        if (!in_array($found[0][1]['type'], self::ADDRESSABLE, true)) {
            throw new ReplaceExactError('unsupported_content', 'unsupported_block');
        }

        return $found[0];
    }

    /** Runs over a normalized block, byte offsets into the UTF-8 block text. */
    public static function blockText(array $block): array
    {
        $text = '';
        $runs = [];
        foreach ($block['content'] ?? [] as $index => $child) {
            $start = strlen($text);
            $isText = $child['type'] === 'text';
            $text .= $isText ? $child['text'] : self::LEAF;
            $runs[] = ['start' => $start, 'end' => strlen($text), 'index' => $index, 'marks' => $isText ? ($child['marks'] ?? []) : null];
        }

        return [$text, $runs];
    }

    /** Overlapping occurrences: strpos from every next byte (substr_count would miss "1-1" twice in "1-1-1"). */
    public static function occurrences(string $text, string $expected): array
    {
        $starts = [];
        for ($at = strpos($text, $expected); $at !== false; $at = strpos($text, $expected, $at + 1)) {
            $starts[] = $at;
        }

        return $starts;
    }

    public static function plan(array $doc, array $args): array
    {
        $doc = self::normalize($doc);
        foreach (['blockId', 'expected', 'replacement'] as $field) {
            if (!is_string($args[$field] ?? null) || !mb_check_encoding($args[$field], 'UTF-8')) {
                throw new ReplaceExactError('invalid_input', 'invalid_arguments');
            }
        }
        if ($args['blockId'] === '' || $args['expected'] === ''
            || str_contains($args['expected'], self::LEAF) || str_contains($args['replacement'], self::LEAF)) {
            throw new ReplaceExactError('invalid_input', 'invalid_arguments');
        }
        [, $block] = self::findBlock($doc, $args['blockId']);
        [$text, $runs] = self::blockText($block);
        $starts = self::occurrences($text, $args['expected']);
        if ($starts === []) {
            throw new ReplaceExactError('precondition_failed', 'expected_absent');
        }
        if (count($starts) > 1) {
            throw new ReplaceExactError('invalid_input', 'ambiguous_target');
        }
        $start = $starts[0];
        $end = $start + strlen($args['expected']);
        foreach ($runs as $run) {
            if ($run['marks'] !== null && $run['start'] <= $start && $end <= $run['end']) {
                return [
                    'blockId' => $args['blockId'],
                    'block' => $block,
                    'startUtf16' => self::utf16Length(substr($text, 0, $start)),
                    'endUtf16' => self::utf16Length(substr($text, 0, $end)),
                    'startByte' => $start,
                    'marks' => $run['marks'],
                ];
            }
        }
        throw new ReplaceExactError('unsupported_content', 'crosses_formatting_or_inline');
    }

    public static function apply(array $doc, array $args, array $pinned): array
    {
        $doc = self::normalize($doc);
        [$index, $block] = self::findBlock($doc, $args['blockId']);
        if (json_encode(self::canonicalBlock($block)) !== json_encode(self::canonicalBlock($pinned['block']))) {
            throw new ReplaceExactError('precondition_failed', 'target_changed');
        }
        [$text, $runs] = self::blockText($block);
        $start = $pinned['startByte'];
        $end = $start + strlen($args['expected']);
        foreach ($runs as $run) {
            if ($run['marks'] !== null && $run['start'] <= $start && $end <= $run['end']) {
                $node = $block['content'][$run['index']];
                $offset = $start - $run['start'];
                $node['text'] = substr($node['text'], 0, $offset) . $args['replacement']
                    . substr($node['text'], $offset + strlen($args['expected']));
                $block['content'][$run['index']] = $node;
                $block = self::normalize($block);
                $doc = self::setAt($doc, $index, $block);
                [$after] = self::blockText($block);

                return ['doc' => $doc, 'evidence' => [
                    'block_id' => $args['blockId'],
                    'start_utf16' => $pinned['startUtf16'],
                    'length_utf16' => self::utf16Length($args['replacement']),
                    'replacement' => $args['replacement'],
                    'marks' => $args['replacement'] === '' ? [] : $pinned['marks'],
                    'committed_text' => $after,
                ]];
            }
        }
        throw new ReplaceExactError('precondition_failed', 'target_changed');
    }

    /** Schema defaults are part of identity (ProseMirror Node.eq sees filled attrs). */
    private static function canonicalBlock(array $block): array
    {
        $block = self::sortKeys(self::normalize($block));
        foreach ($block['content'] ?? [] as $i => $child) {
            if (isset($child['marks'])) {
                $marks = $child['marks'];
                usort($marks, static fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
                $block['content'][$i]['marks'] = $marks;
            }
        }

        return $block;
    }

    public static function replace(array $doc, array $args): array
    {
        return self::apply($doc, $args, self::plan($doc, $args));
    }
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $vectors = json_decode((string) file_get_contents($argv[1] ?? __DIR__ . '/vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    $results = ['cases' => [], 'commit_after' => []];
    $failures = 0;
    foreach ($vectors['cases'] as $case) {
        try {
            $plan = ReplaceExact::plan($vectors['doc'], $case['args']);
            $out = ReplaceExact::apply($vectors['doc'], $case['args'], $plan);
            [$text] = ReplaceExact::blockText(ReplaceExact::findBlock($out['doc'], $case['args']['blockId'])[1]);
            $ok = isset($case['ok']) && $case['ok']['block_text'] === $text
                && $case['ok']['start_utf16'] === $plan['startUtf16'] && $case['ok']['start_utf8_bytes'] === $plan['startByte'];
            $results['cases'][$case['id']] = ['doc' => $out['doc'], 'evidence' => $out['evidence']];
        } catch (ReplaceExactError $e) {
            $ok = ($case['error'] ?? null) === [$e->wireCode, $e->reason];
            $results['cases'][$case['id']] = ['error' => [$e->wireCode, $e->reason]];
        }
        $failures += $ok ? 0 : 1;
        fwrite(STDERR, ($ok ? 'ok   ' : 'FAIL ') . "php {$case['id']}\n");
    }
    $byId = array_column($vectors['cases'], null, 'id');
    foreach ($vectors['commit_after'] as $variant) {
        $args = $byId[$variant['case']]['args'];
        $pinned = ReplaceExact::plan($vectors['doc'], $args);
        $fresh = $vectors['doc'];
        if (isset($variant['edit'])) {
            $fresh = ReplaceExact::replace($fresh, $variant['edit'])['doc'];
        }
        if (isset($variant['set_link_href'])) {
            $fresh = ReplaceExact::normalize($fresh);
            [$path, $block] = ReplaceExact::findBlock($fresh, $args['blockId']);
            foreach ($block['content'] as $j => $child) {
                foreach ($child['marks'] ?? [] as $k => $mark) {
                    if ($mark['type'] === 'link') {
                        $block['content'][$j]['marks'][$k]['attrs']['href'] = $variant['set_link_href'];
                    }
                }
            }
            $fresh = ReplaceExact::setAt($fresh, $path, $block);
        }
        try {
            ReplaceExact::apply($fresh, $args, $pinned);
            $got = 'ok';
        } catch (ReplaceExactError $e) {
            $got = [$e->wireCode, $e->reason];
        }
        $ok = $got === $variant['result'];
        $results['commit_after'][$variant['id']] = $got;
        $failures += $ok ? 0 : 1;
        fwrite(STDERR, ($ok ? 'ok   ' : 'FAIL ') . "php commit_after {$variant['id']}\n");
    }
    echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($failures === 0 ? 0 : 1);
}
