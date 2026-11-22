<?php

declare(strict_types=1);

namespace Vaulter\Documents\Content\TipTap;

use Closure;
use Packman\Attributes\Api;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;

/**
 * Stable block identity for addressable rich-text nodes (`paragraph`, `heading`).
 *
 * ProseMirror / TipTap JSON stores the identifier under `attrs.blockId`; the wire
 * contract exposes it as `block_id`.
 */
#[Api(since: '1.0')]
final readonly class BlockIdentity
{
    public const string ATTR_KEY = 'blockId';

    public const string WIRE_KEY = 'block_id';

    public const int MAX_LENGTH = 64;

    /** @var list<string> */
    public const array ADDRESSABLE_NODES = ['paragraph', 'heading'];

    public static function isAddressable(string $nodeType): bool
    {
        return in_array($nodeType, self::ADDRESSABLE_NODES, true);
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= self::MAX_LENGTH
            && preg_match('/^[A-Za-z0-9_-]+$/', $value) === 1;
    }

    public static function generate(): string
    {
        return 'blk_'.bin2hex(random_bytes(8));
    }

    /**
     * Assign unique block IDs to any addressable node whose `attrs.blockId` is missing,
     * invalid, or duplicates an earlier block in the same document.
     *
     * @param  array<string, mixed>     $doc
     * @param  (Closure(): string)|null $generator
     * @return array<string, mixed>
     */
    public static function assignMissing(array $doc, ?Closure $generator = null): array
    {
        return self::normalize($doc, $generator)['doc'];
    }

    /**
     * Normalize all addressable blocks in a TipTap JSON document and report whether any
     * node was modified.
     *
     * @param  array<string, mixed>                            $doc
     * @param  (Closure(): string)|null                        $generator
     * @return array{doc: array<string, mixed>, changed: bool}
     */
    public static function normalize(array $doc, ?Closure $generator = null): array
    {
        $seen = [];
        $changed = false;
        $makeId = $generator ?? static fn (): string => self::generate();

        $normalized = self::walkAssign($doc, $seen, $changed, $makeId, forceRegenerate: false);

        return ['doc' => $normalized, 'changed' => $changed];
    }

    /**
     * Replace every addressable block's ID with a fresh unique ID (copy/paste semantics).
     *
     * @param  array<string, mixed>     $doc
     * @param  (Closure(): string)|null $generator
     * @return array<string, mixed>
     */
    public static function regenerateAll(array $doc, ?Closure $generator = null): array
    {
        $seen = [];
        $changed = false;
        $makeId = $generator ?? static fn (): string => self::generate();

        return self::walkAssign($doc, $seen, $changed, $makeId, forceRegenerate: true);
    }

    /**
     * Verify that every present `attrs.blockId` on addressable nodes is valid and unique
     * within the document.
     *
     * @param array<string, mixed> $doc
     *
     * @throws InvalidDocumentContentException
     */
    public static function assertUnique(array $doc): void
    {
        $seen = [];
        self::walkAssert($doc, $seen);
    }

    /**
     * Extract all non-null `attrs.blockId` values in document traversal order.
     *
     * @param  array<string, mixed> $doc
     * @return list<string>
     */
    public static function extractIds(array $doc): array
    {
        $ids = [];
        self::walkExtract($doc, $ids);

        return $ids;
    }

    /**
     * @param  array<string, mixed> $node
     * @param  array<string, true>  $seen
     * @param  Closure(): string    $makeId
     * @return array<string, mixed>
     */
    private static function walkAssign(
        array $node,
        array &$seen,
        bool &$changed,
        Closure $makeId,
        bool $forceRegenerate,
    ): array {
        $type = $node['type'] ?? null;

        if (is_string($type) && self::isAddressable($type)) {
            $attrs = isset($node['attrs']) && is_array($node['attrs']) ? $node['attrs'] : [];
            $existing = $attrs[self::ATTR_KEY] ?? null;

            if (!$forceRegenerate && is_string($existing) && self::isValid($existing) && !isset($seen[$existing])) {
                $seen[$existing] = true;
            } else {
                $id = self::nextUniqueId($seen, $makeId);
                $seen[$id] = true;
                $attrs[self::ATTR_KEY] = $id;
                $node['attrs'] = $attrs;
                $changed = true;
            }
        }

        if (isset($node['content']) && is_array($node['content'])) {
            $children = [];
            foreach ($node['content'] as $child) {
                if (is_array($child)) {
                    /** @var array<string, mixed> $child */
                    $children[] = self::walkAssign($child, $seen, $changed, $makeId, $forceRegenerate);
                } else {
                    $children[] = $child;
                }
            }
            $node['content'] = $children;
        }

        return $node;
    }

    /**
     * @param array<string, true> $seen
     * @param Closure(): string   $makeId
     */
    private static function nextUniqueId(array $seen, Closure $makeId): string
    {
        do {
            $candidate = $makeId();
        } while (!self::isValid($candidate) || isset($seen[$candidate]));

        return $candidate;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, true>  $seen
     */
    private static function walkAssert(array $node, array &$seen): void
    {
        $type = $node['type'] ?? null;

        if (is_string($type) && self::isAddressable($type) && isset($node['attrs']) && is_array($node['attrs'])) {
            $blockId = $node['attrs'][self::ATTR_KEY] ?? null;

            if ($blockId !== null) {
                if (!self::isValid($blockId)) {
                    throw InvalidDocumentContentException::disallowedAttribute($type, self::ATTR_KEY);
                }

                /** @var string $blockId */
                if (isset($seen[$blockId])) {
                    throw InvalidDocumentContentException::duplicateBlockId($blockId);
                }

                $seen[$blockId] = true;
            }
        }

        if (isset($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as $child) {
                if (is_array($child)) {
                    /** @var array<string, mixed> $child */
                    self::walkAssert($child, $seen);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string>         $ids
     */
    private static function walkExtract(array $node, array &$ids): void
    {
        $type = $node['type'] ?? null;

        if (is_string($type) && self::isAddressable($type) && isset($node['attrs']) && is_array($node['attrs'])) {
            $blockId = $node['attrs'][self::ATTR_KEY] ?? null;
            if (is_string($blockId) && $blockId !== '') {
                $ids[] = $blockId;
            }
        }

        if (isset($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as $child) {
                if (is_array($child)) {
                    /** @var array<string, mixed> $child */
                    self::walkExtract($child, $ids);
                }
            }
        }
    }
}
