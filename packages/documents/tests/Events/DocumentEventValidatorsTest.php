<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Operations\OperationsTestCase;
use Vaulter\Enums\Events\EventType;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;

/**
 * Document events carry the validators of the writer's result (state_version, epoch),
 * not those of the possibly older head checkpoint.
 */
uses(OperationsTestCase::class, RefreshDatabase::class, EnablesWebhooksModule::class);

it('takes state_version and epoch of every save from the writer, not from the checkpoint', function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 3600);
    $documents = Documents::in(DocumentTestHelpers::drive());
    $node = $documents->create('Spec', content: DocumentTestHelpers::content('one')->toArray());
    $seen = [];
    Event::listen(DocumentContentUpdated::class, static function (DocumentContentUpdated $event) use (&$seen): void {
        $seen[] = [$event->created, $event->stateVersion, $event->epoch];
    });

    $documents->update($node, DocumentTestHelpers::content('two')->toArray());
    $afterSecond = Document::query()->where('node_id', $node->id)->sole();
    $documents->update($node, DocumentTestHelpers::content('three')->toArray());
    $afterThird = Document::query()->where('node_id', $node->id)->sole();

    expect($seen)->toBe([
        [false, $afterSecond->state_version, $afterSecond->collab_epoch],
        [false, $afterThird->state_version, $afterThird->collab_epoch],
    ])->and($afterThird->state_version)->toBeGreaterThan($afterSecond->state_version)
        // Neither save took a checkpoint, so no webhook was staged for them.
        ->and(Outbox::query()->where('event', EventType::DocumentUpdated->value)->exists())->toBeFalse();
});

it('puts the writer validators of a checkpointed save into the envelope', function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
    $documents = Documents::in(DocumentTestHelpers::drive());
    $node = $documents->create('Spec', content: DocumentTestHelpers::content('one')->toArray());
    $documents->update($node, DocumentTestHelpers::content('two')->toArray());

    $document = Document::query()->where('node_id', $node->id)->sole();
    $data = Outbox::query()->where('event', EventType::DocumentUpdated->value)->sole()->payload['data'];

    expect($data['state_version'])->toBe($document->state_version)
        ->and($data['epoch'])->toBe($document->collab_epoch)
        ->and($data['checkpoint'])->toBeTrue();
});
