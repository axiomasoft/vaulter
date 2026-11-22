<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\ChangeDocumentWriteModeAction;
use Vaulter\Documents\Actions\Documents\DocumentWriteLock;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\OpaqueJsonCodec;
use Vaulter\Documents\Contracts\AtomicDocumentMutation;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Contracts\LockedDocumentMutation;
use Vaulter\Documents\Contracts\RequiresCommandWrite;
use Vaulter\Documents\Data\Documents\DocumentMutationState;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Exceptions\DocumentCommandRequiredException;
use Vaulter\Documents\Exceptions\DocumentStatePendingException;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\Exceptions\StaleDocumentStateException;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Fixtures\Collaboration\WithAcme;
use Vaulter\Documents\Tests\Fixtures\GrantedDocuments;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

uses(WithAcme::class);

beforeEach(function (): void {
    $this->database = app(VaulterDatabase::class);
    $this->database->connection()->getSchemaBuilder()->create('atomic_mutation_receipts', static function (Blueprint $table): void {
        $table->string('receipt')->primary();
        $table->string('document_id');
    });
    $this->port = app(AtomicDocumentMutation::class);
    $this->node = GrantedDocuments::document(level: null);
    $this->context = DocumentTestHelpers::context($this->node);
});

afterEach(function (): void {
    $this->database->connection()->getSchemaBuilder()->dropIfExists('atomic_mutation_receipts');
});

it('reads the working state and closes the scoped handle when the callback returns', function (): void {
    $locked = $this->port->run($this->context, $this->node->id, function (LockedDocumentMutation $locked): LockedDocumentMutation {
        $state = $locked->snapshot();
        expect($locked->connection())->toBe($this->database->connection())
            ->and(fn () => clone $locked)->toThrow(Error::class)
            ->and($state->nodeId)->toBe((string) $this->node->id)
            ->and($state->documentId)->toBe((string) GrantedDocuments::aggregate($this->node)->id)
            ->and($state->type)->toBe('vaulter/rich-text')
            ->and($state->schemaVersion)->toBe(1)
            ->and($state->generation)->toBe(0)
            ->and($state->position)->toBe(1)
            ->and($state->writeMode)->toBe('snapshot')
            ->and($state->protocol)->toBe('yjs')
            ->and($state->pending)->toBeFalse()
            ->and($state->content->toArray())->toBe(GrantedDocuments::tiptap('Draft'));

        return $locked;
    });

    expect(fn () => $locked->snapshot())->toThrow(LogicException::class)
        ->and(fn () => $locked->connection())->toThrow(LogicException::class)
        ->and(fn () => $locked->replace(DocumentTestHelpers::content(), 1))->toThrow(LogicException::class)
        ->and($this->port->run($this->context, $this->node->id, static fn () => null))->toBeNull()
        ->and(GrantedDocuments::aggregate($this->node)->state_version)->toBe(1);
});

it('records one effective replacement and leaves the position and events alone on a no-op', function (): void {
    Event::fake([DocumentContentUpdated::class]);
    $write = fn (int $position) => $this->port->run($this->context, $this->node->id, static function (LockedDocumentMutation $locked) use ($position): DocumentMutationState {
        $state = $locked->replace(DocumentTestHelpers::content('New'), $position);
        expect($locked->snapshot())->toBe($state)
            ->and(fn () => $locked->replace(DocumentTestHelpers::content('Twice'), $state->position))->toThrow(LogicException::class);

        return $state;
    });

    expect($write(1)->position)->toBe(2)
        ->and($write(2)->position)->toBe(2)
        ->and(GrantedDocuments::aggregate($this->node)->payload?->content)->toEqual(GrantedDocuments::tiptap('New'));
    Event::assertDispatchedTimes(DocumentContentUpdated::class, 1);
});

it('rolls back content, receipt and event on callback failure and closes its handle', function (): void {
    Event::fake([DocumentContentUpdated::class]);
    $escaped = null;
    $error = new RuntimeException('Abort after writing the receipt');

    expect(function () use (&$escaped, $error): void {
        $this->port->run($this->context, $this->node->id, function (LockedDocumentMutation $locked) use (&$escaped, $error): never {
            $escaped = $locked;
            $state = $locked->replace(DocumentTestHelpers::content('Aborted'), 1);
            $locked->connection()->table('atomic_mutation_receipts')->insert(['receipt' => 'aborted', 'document_id' => $state->documentId]);

            throw $error;
        });
    })->toThrow($error);

    expect($escaped)->toBeInstanceOf(LockedDocumentMutation::class)
        ->and(fn () => $escaped->snapshot())->toThrow(LogicException::class)
        ->and($this->database->connection()->table('atomic_mutation_receipts')->count())->toBe(0)
        ->and(GrantedDocuments::aggregate($this->node)->state_version)->toBe(1)
        ->and(GrantedDocuments::aggregate($this->node)->payload?->content)->toEqual(GrantedDocuments::tiptap('Draft'));
    Event::assertNotDispatched(DocumentContentUpdated::class);
});

it('refuses stale versions and a nested transaction', function (): void {
    expect(fn () => $this->port->run($this->context, $this->node->id, static fn (LockedDocumentMutation $locked) => $locked->replace(DocumentTestHelpers::content(), 0)))
        ->toThrow(StaleDocumentStateException::class)
        ->and(fn () => $this->database->transaction(fn () => $this->port->run($this->context, $this->node->id, static fn () => null)))
        ->toThrow(LogicException::class)
        ->and(GrantedDocuments::aggregate($this->node)->state_version)->toBe(1);
});

it('gives the same refusal for a missing, non-document, foreign, or uneditable node', function (): void {
    GrantedDocuments::configure();
    AccessTestHelpers::grant($this->node, RoleRegistry::READER, subjectId: 2);
    $forbidden = DocumentTestHelpers::context($this->node, 2);
    $foreign = DocumentTestHelpers::context(DocumentTestHelpers::drive());
    $root = $this->context->drive->rootNode()->firstOrFail();
    $callback = static fn () => throw new RuntimeException('The callback must stay inaccessible');
    $message = NodeNotFoundException::forId('document')->getMessage();

    expect(fn () => $this->port->run($this->context, 'invalid:id', $callback))->toThrow(NodeNotFoundException::class, $message)
        ->and(fn () => $this->port->run($this->context, $root->id, $callback))->toThrow(NodeNotFoundException::class, $message)
        ->and(fn () => $this->port->run($foreign, $this->node->id, $callback))->toThrow(NodeNotFoundException::class, $message)
        ->and(fn () => $this->port->run($forbidden, $this->node->id, $callback))->toThrow(NodeNotFoundException::class, $message);
});

it('exposes a live journal snapshot but refuses a replacement', function (): void {
    GrantedDocuments::configure();
    $node = GrantedDocuments::document(level: RoleRegistry::OWNER, content: OpaqueContent::fromArray(['columns' => ['Draft']]), type: 'acme/ot');
    $context = DocumentTestHelpers::context($node, 1);
    app(ChangeDocumentWriteModeAction::class)->execute($context, $node, expectedGeneration: 0, expectedPosition: 1);

    $this->port->run($context, $node->id, static function (LockedDocumentMutation $locked): void {
        $state = $locked->snapshot();
        expect($state->writeMode)->toBe('journal')
            ->and($state->protocol)->toBe('acme-ot')
            ->and($state->generation)->toBe(1)
            ->and(fn () => $locked->replace(OpaqueContent::fromArray(['columns' => []]), $state->position))
            ->toThrow(DocumentCommandRequiredException::class);
    });
    expect(GrantedDocuments::aggregate($node)->state_version)->toBe(2);
});

it('exposes the pending fence and refuses to overwrite the working state', function (): void {
    $this->database->transaction(function (): void {
        [, $document] = app(DocumentWriteLock::class)->acquire($this->context, $this->node, $this->node->id);
        app(DocumentStateWriter::class)->mark($document, generation: 0, throughSeq: 3);
    });
    $this->port->run($this->context, $this->node->id, static function (LockedDocumentMutation $locked): void {
        expect($locked->snapshot()->pending)->toBeTrue()
            ->and(fn () => $locked->replace(DocumentTestHelpers::content('Overwrite'), 1))->toThrow(DocumentStatePendingException::class);
    });
    expect(GrantedDocuments::aggregate($this->node)->state_version)->toBe(1);
});

it('routes a command-only type through the port and enforces the codec size limit', function (): void {
    $type = Mockery::mock(DocumentType::class, RequiresCommandWrite::class);
    $type->allows(['key' => 'test/command', 'schemaVersion' => 1, 'codec' => new OpaqueJsonCodec(maxSizeBytes: 32)]);
    $registry = new DocumentTypeRegistry(app());
    $registry->register($type);
    $this->app->instance(DocumentTypeRegistry::class, $registry);

    $initial = OpaqueContent::fromArray(['value' => 'before']);
    $changed = OpaqueContent::fromArray(['value' => 'after']);
    $oversize = OpaqueContent::fromArray(['value' => str_repeat('x', 40)]);
    $node = GrantedDocuments::document(level: null, content: $initial, type: 'test/command');
    $context = DocumentTestHelpers::context($node);
    $port = app(AtomicDocumentMutation::class);
    $autosave = app(UpdateDocumentContentAction::class);

    expect(fn () => $autosave->execute($context, new UpdateDocumentContentInput(node: $node, content: $changed)))
        ->toThrow(DocumentCommandRequiredException::class)
        ->and(fn () => $port->run($context, $node->id, static fn (LockedDocumentMutation $locked) => $locked->replace($oversize, 1)))
        ->toThrow(InvalidDocumentContentException::class)
        ->and($port->run($context, $node->id, static fn (LockedDocumentMutation $locked) => $locked->replace($changed, 1))->position)
        ->toBe(2)
        ->and(GrantedDocuments::aggregate($node)->payload?->content)->toBe($changed->toArray());
});

it('refuses to publish counters beyond the wire range and keeps an unchanged snapshot at the limit', function (): void {
    $max = DocumentMutationState::MAX_WIRE_COUNTER;
    $document = GrantedDocuments::aggregate($this->node);
    $this->database->transaction(static fn () => $document->forceFill(['state_version' => $max])->save());

    $this->port->run($this->context, $this->node->id, static function (LockedDocumentMutation $locked) use ($max): void {
        expect($locked->replace($locked->snapshot()->content, $max)->position)->toBe($max);
    });
    $this->port->run($this->context, $this->node->id, static function (LockedDocumentMutation $locked) use ($max): void {
        expect(fn () => $locked->replace(DocumentTestHelpers::content('Overflow'), $max))->toThrow(OverflowException::class)
            ->and($locked->snapshot()->position)->toBe($max);
    });
    expect(GrantedDocuments::aggregate($this->node)->state_version)->toBe($max);

    $this->database->transaction(static fn () => $document->forceFill(['collab_epoch' => $max + 1])->save());
    expect(fn () => $this->port->run($this->context, $this->node->id, static fn () => null))
        ->toThrow(OverflowException::class);
});

it('retries the whole callback after :dataset and only commits the final receipt and event', function (string $sqlstate): void {
    Event::fake([DocumentContentUpdated::class]);
    $attempts = [];
    $receipt = $this->port->run($this->context, $this->node->id, static function (LockedDocumentMutation $locked) use (&$attempts, $sqlstate): string {
        $attempts[] = $locked;
        $attempt = count($attempts);
        $connection = $locked->connection();
        expect($locked->snapshot()->position)->toBe(1)
            ->and($connection->table('atomic_mutation_receipts')->count())->toBe(0);

        $state = $locked->replace(DocumentTestHelpers::content("Attempt {$attempt}"), 1);
        $receipt = "attempt-{$attempt}";
        $connection->table('atomic_mutation_receipts')->insert(['receipt' => $receipt, 'document_id' => $state->documentId]);

        if ($attempt === 1) {
            if ($connection->getDriverName() === 'pgsql') {
                $connection->unprepared("DO \$\$ BEGIN RAISE EXCEPTION 'retry test' USING ERRCODE = '{$sqlstate}'; END \$\$");
            }

            throw new PDOException("SQLSTATE[{$sqlstate}]: retry test");
        }

        return $receipt;
    });

    expect($attempts)->toHaveCount(2)
        ->and($attempts[0])->not->toBe($attempts[1])
        ->and(fn () => $attempts[0]->snapshot())->toThrow(LogicException::class)
        ->and($receipt)->toBe('attempt-2')
        ->and($this->database->connection()->table('atomic_mutation_receipts')->pluck('receipt')->all())->toBe([$receipt])
        ->and(GrantedDocuments::aggregate($this->node)->state_version)->toBe(2)
        ->and(GrantedDocuments::aggregate($this->node)->payload?->content)->toEqual(GrantedDocuments::tiptap('Attempt 2'));
    Event::assertDispatchedTimes(DocumentContentUpdated::class, 1);
})->with(['40001', '40P01']);
