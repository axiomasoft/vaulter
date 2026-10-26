<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\State\CollaborationStateBinding;
use Vaulter\Documents\State\CollaborativeState;
use Vaulter\Documents\State\CollaborativeStateTransition;
use Vaulter\Documents\Tests\Fixtures\GrantedDocuments;
use Vaulter\ValueObjects\Access\ActorRef;

it('compares every stored binding component', function (): void {
    $binding = new CollaborationStateBinding('yjs', 'yjs-update-v1', 1, 7);
    expect($binding->sameAs(new CollaborationStateBinding('yjs', 'yjs-update-v1', 1, 7)))->toBeTrue();
    foreach ([['acme-ot', 'yjs-update-v1', 1, 7], ['yjs', 'other', 1, 7], ['yjs', 'yjs-update-v1', 2, 7], ['yjs', 'yjs-update-v1', 1, 8]] as $values) {
        expect($binding->sameAs(new CollaborationStateBinding(...$values)))->toBeFalse();
    }
});

it('fences identical JSON transitions and retains explicit user attribution', function (): void {
    $node = GrantedDocuments::document(level: null);
    $actor = new ActorRef('staff', '42');
    $before = GrantedDocuments::aggregate($node);
    $outcome = app(VaulterDatabase::class)->transaction(function () use ($node, $actor) {
        $state = app(CollaborativeState::class);
        $locked = $state->lock($node->getKey());

        return $state->transition($locked, new CollaborativeStateTransition(
            expectedBinding: $locked->binding,
            expectedStateVersion: $locked->stateVersion,
            documentType: $locked->type,
            schemaVersion: $locked->schemaVersion,
            protocol: $locked->binding?->protocol,
            stateFormat: $locked->binding?->stateFormat,
            content: OpaqueContent::fromArray($locked->content),
            collabState: null,
            actor: $actor,
            correlationId: 'transition-42',
        ));
    });
    $after = GrantedDocuments::aggregate($node);
    expect($outcome->published)->toBeTrue()
        ->and($after->collab_epoch)->toBe($before->collab_epoch + 1)
        ->and($after->state_version)->toBe($before->state_version + 1)
        ->and($after->content_hash)->toBe($before->content_hash)
        ->and((string) $after->updated_by)->toBe('42')
        ->and((string) $after->currentRevision->created_by)->toBe('42')
        ->and($after->currentRevision->type)->toBe($before->type);
});
