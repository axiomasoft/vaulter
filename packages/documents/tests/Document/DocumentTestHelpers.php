<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Document;

use Illuminate\Database\Eloquent\Collection;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Drive\DriveHandle;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;

/**
 * Builders for the M4a Documents tests: a Drive handle and a quick TipTap document.
 */
final class DocumentTestHelpers
{
    public static function drive(): DriveHandle
    {
        return DriveTestHelpers::drive();
    }

    /**
     * A small TipTap doc with one paragraph of the given text.
     */
    public static function content(string $text = 'Hello'): TipTapContent
    {
        return TipTapContent::fromArray([
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => $text],
                    ],
                ],
            ],
        ]);
    }

    /** The document aggregate of a node, read fresh. */
    public static function document(Node $node): Document
    {
        return Document::query()->where('node_id', $node->getKey())->firstOrFail();
    }

    /**
     * The node's checkpoints, oldest number first.
     *
     * @return Collection<int, DocumentRevision>
     */
    public static function revisions(Node $node): Collection
    {
        return DocumentRevision::query()->where('document_id', self::document($node)->getKey())->orderBy('number')->get();
    }

    public static function head(Node $node): DocumentRevision
    {
        return DocumentRevision::query()->whereKey(self::document($node)->current_revision_id)->firstOrFail();
    }
}
