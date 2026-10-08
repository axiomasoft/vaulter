<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Document;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Drive\DriveHandle;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\ValueObjects\Access\Actor;

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

    /**
     * The operation context a direct Action call runs under: the drive of $target, as
     * the system, or as the test user $actorId when given.
     */
    public static function context(Drive|Node|DriveHandle $target, int|string|null $actorId = null): OperationContext
    {
        $drive = match (true) {
            $target instanceof Node => $target->storage()->firstOrFail(),
            $target instanceof DriveHandle => $target->model(),
            default => $target,
        };
        if ($actorId === null) {
            return OperationContext::system($drive, 'test fixture');
        }
        $user = new TestUser;
        $user->forceFill(['id' => $actorId]);
        $user->exists = true;

        return new OperationContext($drive, Actor::user($user), (string) Str::ulid());
    }
}
