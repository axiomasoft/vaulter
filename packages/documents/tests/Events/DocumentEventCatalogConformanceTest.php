<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Operations\OperationsTestCase;
use Vaulter\Enums\Events\EventType;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Tests\Events\EventConformance;

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
        EventType::DocumentCreated, EventType::DocumentUpdated, EventType::DocumentRevisionCreated, EventType::DocumentRestored,
        EventType::CommentAdded, EventType::CommentResolved, EventType::CommentDeleted,
    ]);
});
