<?php

declare(strict_types=1);

namespace VaulterRef\Ai\Documents\Writing;

use VaulterRef\Documents\Content\ContentHasher;

/**
 * Target: packages/documents/src/Commands/ReplaceExact.php (owner-side apply) in vaulter-ai. Builds the new
 * document from the FRESH locked snapshot; it never writes back an old full JSON.
 *
 * Owner rules (prepare checked the same; the owner re-checks because the snapshot may differ):
 *  - exactly one paragraph/heading carries attrs.blockId;
 *  - the block's canonical hash equals the hash pinned at prepare (any change of the target block, text, marks
 *    or link attributes → precondition_failed; neighbouring blocks may change freely);
 *  - the fragment occurs exactly once in the block text (overlapping starts counted; non-text inline nodes break
 *    the text) and lies inside one text node;
 *  - that text node's marks, attributes included, equal the pinned marks.
 * Byte offsets are safe here because UTF-8 substring search cannot match inside a code point; UTF-16 offsets
 * belong to the browser/ProseMirror adapter only.
 */
final class TextReplacement
{
    private const BARRIER = "\u{FFFC}";

    /** @param array<string, mixed> $block */
    public static function blockHash(array $block): string
    {
        return ContentHasher::hash($block);
    }

    /**
     * @param  array<string, mixed>                                      $doc
     * @return array{doc: array<string, mixed>, block: array<string, mixed>}|string reason on refusal
     */
    public static function apply(array $doc, ReplaceExactPlan $plan): array|string
    {
        $paths = [];
        self::collect($doc, [], $plan->blockId, $paths);
        if (count($paths) !== 1) {
            return 'precondition_failed';
        }
        $block = self::get($doc, $paths[0]);
        if (!in_array($block['type'] ?? null, ['paragraph', 'heading'], true) || !hash_equals($plan->sourceBlockHash, self::blockHash($block))) {
            return 'precondition_failed';
        }
        if (str_contains($plan->expected, self::BARRIER)) {
            return 'precondition_failed';
        }
        $children = array_values($block['content'] ?? []);
        $text = '';
        $spans = [];
        foreach ($children as $index => $child) {
            if (($child['type'] ?? null) !== 'text' || !is_string($child['text'] ?? null)) {
                $text .= self::BARRIER;
                continue;
            }
            $spans[] = [$index, strlen($text), strlen($text) + strlen($child['text'])];
            $text .= $child['text'];
        }
        $starts = [];
        for ($offset = strpos($text, $plan->expected); $offset !== false; $offset = strpos($text, $plan->expected, $offset + 1)) {
            $starts[] = $offset;
        }
        if (count($starts) !== 1) {
            return 'precondition_failed';
        }
        $start = $starts[0];
        $end = $start + strlen($plan->expected);
        foreach ($spans as [$index, $from, $to]) {
            if ($start < $from || $end > $to) {
                continue;
            }
            $child = $children[$index];
            if (ContentHasher::hash($child['marks'] ?? []) !== ContentHasher::hash($plan->marks)) {
                return 'precondition_failed';
            }
            $replaced = substr_replace($child['text'], $plan->replacement, $start - $from, strlen($plan->expected));
            if ($replaced === '') {
                array_splice($children, $index, 1); // ProseMirror forbids empty text nodes
            } else {
                $children[$index]['text'] = $replaced;
            }
            $block['content'] = $children;

            return ['doc' => self::set($doc, $paths[0], $block), 'block' => $block];
        }

        return 'precondition_failed'; // the single occurrence crosses a text-node (mark) boundary
    }

    /**
     * @param array<string, mixed> $node
     * @param list<int>            $path
     * @param list<list<int>>      $found
     */
    private static function collect(array $node, array $path, string $blockId, array &$found): void
    {
        if (($node['attrs']['blockId'] ?? null) === $blockId) {
            $found[] = $path;
        }
        foreach (array_values($node['content'] ?? []) as $index => $child) {
            if (is_array($child)) {
                self::collect($child, [...$path, $index], $blockId, $found);
            }
        }
    }

    /**
     * @param  array<string, mixed> $node
     * @param  list<int>            $path
     * @return array<string, mixed>
     */
    private static function get(array $node, array $path): array
    {
        foreach ($path as $index) {
            $node = $node['content'][$index];
        }

        return $node;
    }

    /**
     * @param  array<string, mixed> $node
     * @param  list<int>            $path
     * @param  array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function set(array $node, array $path, array $value): array
    {
        if ($path === []) {
            return $value;
        }
        $index = array_shift($path);
        $node['content'][$index] = self::set($node['content'][$index], $path, $value);

        return $node;
    }
}
