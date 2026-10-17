<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Operations\OperationsTestCase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Testing\Events\EventConformance;

/**
 * The document and comment events, produced by the real Actions, carry exactly the
 * data their EventSpec declares and nothing an envelope must not hold.
 */
uses(OperationsTestCase::class, RefreshDatabase::class, EnablesWebhooksModule::class);

it('records the document and comment events with the data their specs declare', function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
    $handle = DocumentTestHelpers::drive();
    $documents = Documents::in($handle);
    $node = $documents->create('Private title', content: DocumentTestHelpers::content('secret body')->toArray());
    $documents->update($node, DocumentTestHelpers::content('two')->toArray());
    $named = $documents->snapshot($node, 'Named');
    $documents->restore($node, $named);

    $thread = $handle->folders()->create('thread');
    AccessTestHelpers::grant($thread, RoleRegistry::OWNER, subjectId: 9);
    $actor = $handle->actingAs(AccessTestHelpers::user(9));
    $comment = Documents::comments($actor)->add($thread->id, new AddCommentInput('private comment'));
    Documents::comments($actor)->resolve($thread->id, $comment->id);
    Documents::comments($actor)->delete($thread->id, $comment->id);

    EventConformance::assertRecorded([
        DocumentEventType::DocumentCreated, DocumentEventType::DocumentUpdated, DocumentEventType::DocumentRevisionCreated, DocumentEventType::DocumentRestored,
        DocumentEventType::CommentAdded, DocumentEventType::CommentResolved, DocumentEventType::CommentDeleted,
    ]);
});
