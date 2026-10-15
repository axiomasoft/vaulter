<?php

declare(strict_types=1);

namespace Vaulter\Documents\Queries;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Data\Snapshots\CommentSnapshot;
use Vaulter\Documents\Data\Snapshots\DocumentRevisionSnapshot;
use Vaulter\Documents\Exceptions\CommentNotFoundException;
use Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Enums\Drive\NodeSort;
use Vaulter\Models\Drive\Node;

/**
 * Reads behind the documents operations. Every lookup is bound to the node the caller
 * already located and authorized: a revision or comment id of another node resolves
 * to "not found", never to the foreign row. Makes no access decisions itself.
 *
 * @internal
 */
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
        $found = $key === '' || !self::fitsKey(new $model, $key) ? null : $model::query()->where('document_id', $document->getKey())->whereKey($key)->first();

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
        $found = $key === '' || !self::fitsKey(new $model, $key) ? null : $model::query()->where('node_id', $node->getKey())->whereKey($key)->first();

        return $found ?? throw CommentNotFoundException::forNode($node->getKey(), $key);
    }

    /**
     * Whether an untrusted key can name a row of the model: a key its column cannot hold is "not found" and must not reach
     * the query, where PostgreSQL fails the cast (bigint, uuid) and the request ends in a 500. Mirrors core's Identity::fitsKey,
     * which is not part of the public surface documents may import.
     */
    private static function fitsKey(Model $model, int|string $key): bool
    {
        $traits = class_uses_recursive($model);
        if (in_array(HasUuids::class, $traits, true)) {
            return Str::isUuid((string) $key);
        }
        if (in_array(HasUlids::class, $traits, true)) {
            return Str::isUlid((string) $key);
        }

        return $model->getKeyType() !== 'int' || preg_match('/^[0-9]{1,19}$/', (string) $key) === 1;
    }

    private function assertPlainPaging(ListOptions $options): void
    {
        if ($options->sort !== NodeSort::Default || $options->descending || $options->kinds !== []) {
            throw new InvalidArgumentException('Revision and comment lists support perPage and cursor only.');
        }
    }
}
