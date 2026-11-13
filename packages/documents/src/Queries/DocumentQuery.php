<?php

declare(strict_types=1);

namespace Vaulter\Documents\Queries;

use Illuminate\Pagination\CursorPaginator;
use InvalidArgumentException;
use Vaulter\Attributes\Internal;
use Vaulter\Support\Identity;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Data\Snapshots\CommentSnapshot;
use Vaulter\Documents\Data\Snapshots\DocumentRevisionSnapshot;
use Vaulter\Documents\Exceptions\CommentNotFoundException;
use Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Enums\NodeSort;
use Vaulter\Drive\Models\Node;

/**
 * Reads behind the documents operations. Every lookup is bound to the node the caller
 * already located and authorized: a revision or comment id of another node resolves
 * to "not found", never to the foreign row. Makes no access decisions itself.
 */
#[Internal]
final readonly class DocumentQuery
{
    public function __construct(private DocumentsConfig $config) {}

    public function document(Node $node): Document
    {
        $document = Document::query()->where('node_id', $node->getKey())->with('currentRevision')->first();

        return $document ?? throw NotADocumentException::forNode($node->getKey());
    }

    public function revision(Node $node, Document $document, DocumentRevisionSnapshot|int|string $revision): DocumentRevision
    {
        $key = $revision instanceof DocumentRevisionSnapshot ? $revision->id : $revision;
        $model = $this->config->model('document_revision');
        $found = $key === '' || !Identity::fitsKey(new $model, $key) ? null : $model::query()->where('document_id', $document->getKey())->whereKey($key)->first();

        return $found ?? throw DocumentRevisionNotFoundException::forNode($node->getKey(), $key);
    }

    /**
     * Newest first; ties on the timestamp fall back to the key so the cursor is stable.
     *
     * @return CursorPaginator<int, DocumentRevision>
     */
    public function revisions(Document $document, ListOptions $options): CursorPaginator
    {
        $this->assertPlainPaging($options);
        $model = $this->config->model('document_revision');

        return $model::query()
            ->where('document_id', $document->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(perPage: $options->perPage, cursor: $options->cursor);
    }

    /**
     * Oldest first: a thread reads top to bottom.
     *
     * @return CursorPaginator<int, NodeComment>
     */
    public function comments(Node $node, ListOptions $options): CursorPaginator
    {
        $this->assertPlainPaging($options);
        $model = $this->config->model('node_comment');

        return $model::query()
            ->where('node_id', $node->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursorPaginate(perPage: $options->perPage, cursor: $options->cursor);
    }

    public function comment(Node $node, CommentSnapshot|int|string $comment): NodeComment
    {
        $key = $comment instanceof CommentSnapshot ? $comment->id : $comment;
        $model = $this->config->model('node_comment');
        // an id the key column cannot hold names no row; asking PostgreSQL for it throws instead (bigint vs 'missing')
        $found = $key === '' || !Identity::fitsKey(new $model, $key) ? null : $model::query()->where('node_id', $node->getKey())->whereKey($key)->first();

        return $found ?? throw CommentNotFoundException::forNode($node->getKey(), $key);
    }

    private function assertPlainPaging(ListOptions $options): void
    {
        if ($options->sort !== NodeSort::Default || $options->descending || $options->kinds !== []) {
            throw new InvalidArgumentException('Revision and comment lists support perPage and cursor only.');
        }
    }
}
