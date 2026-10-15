<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Data\Drive\MoveOptions;
use Vaulter\Data\Drive\RevisionOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Uploads\CompleteUploadAction;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Uploads\S3MultipartHandler;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Exceptions\Nodes\SequenceOverflowException;
use Vaulter\Exceptions\Nodes\StaleNodeException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;
use Vaulter\Operations\NodeSequence;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(function (): void {
    Storage::fake('local');
    $this->drive = Vaulter::drive(DriveTestHelpers::host())->asSystem('sequence acceptance fixture');
});

function sequenceContractState(): array
{
    return DriveTestHelpers::writeTablesSnapshot();
}

it('SEC-R19-noop checks a current or stale expectation before every effective no-op', function (string $operation): void {
    $node = $this->drive->folders()->create('same');
    $run = fn (int $expected): NodeSnapshot => match ($operation) {
        'rename' => $this->drive->nodes()->rename($node, 'same', $expected),
        'move' => $this->drive->nodes()->move($node, $this->drive->root(), new MoveOptions(expectedSequence: $expected)),
        'setProperties' => $this->drive->nodes()->setProperties($node, 'acme', [], ['absent'], $expected),
    };
    $before = sequenceContractState();
    expect($node->sequence)->toBe(0)->and($run(0)->sequence)->toBe(0)
        ->and(sequenceContractState())->toBe($before);
    try {
        $run(1);
        test()->fail('A stale no-op must reject its expectation.');
    } catch (StaleNodeException $exception) {
        expect($exception->httpStatus())->toBe(412)->and($exception->problemCode())->toBe('node_sequence_mismatch');
    }
    expect(sequenceContractState())->toBe($before);
})->with(['rename', 'move', 'setProperties']);

it('increments sequence once for each metadata or content transition and leaves new nodes at zero', function (): void {
    $folder = $this->drive->folders()->create('folder');
    $file = $this->drive->files()->upload(UploadSource::fromString('first', 'file.txt'));
    expect($folder->sequence)->toBe(0)->and($file->sequence)->toBe(0);
    expect($this->drive->nodes()->rename($file, 'renamed.txt', 0)->sequence)->toBe(1);
    $this->drive->files()->addRevision($file, UploadSource::fromString('second', 'renamed.txt'), new RevisionOptions(expectedSequence: 1));
    expect(DriveTestHelpers::model($file)->sequence)->toBe(2)
        ->and($this->drive->nodes()->move($file, $folder, new MoveOptions(expectedSequence: 2))->sequence)->toBe(3)
        ->and($this->drive->nodes()->setProperties($file, 'acme', ['k' => 'v'], [], 3)->sequence)->toBe(4);
    $revisions = $this->drive->files()->revisions($file)->items;
    $oldest = collect($revisions)->sortBy('number')->first();
    $this->drive->files()->restoreRevision($file, $oldest);
    expect(DriveTestHelpers::model($file)->sequence)->toBe(5);
    $copy = $this->drive->nodes()->copy($file);
    expect($copy->sequence)->toBe(0)->and(DriveTestHelpers::model($file)->sequence)->toBe(5);
});

it('SEC-R20-subtree increments every descendant once on trash and restore', function (): void {
    $top = $this->drive->folders()->create('top');
    $middle = $this->drive->folders()->create('middle', $top);
    $leaf = $this->drive->folders()->create('leaf', $middle);
    $outside = $this->drive->folders()->create('outside');
    $this->drive->nodes()->rename($middle, 'middle changed', 0);
    $this->drive->nodes()->trash($top, 0);
    expect(DriveTestHelpers::model($top)->sequence)->toBe(1)->and(DriveTestHelpers::model($middle)->sequence)->toBe(2)
        ->and(DriveTestHelpers::model($leaf)->sequence)->toBe(1)->and(DriveTestHelpers::model($outside)->sequence)->toBe(0);
    $this->drive->trash()->restore($top);
    expect(DriveTestHelpers::model($top)->sequence)->toBe(2)->and(DriveTestHelpers::model($middle)->sequence)->toBe(3)
        ->and(DriveTestHelpers::model($leaf)->sequence)->toBe(2)->and(DriveTestHelpers::model($outside)->sequence)->toBe(0);
    foreach ([$top, $middle, $leaf] as $node) {
        expect($node->trashedAt)->toBeNull();
    }
});

it('SEC-R20-overflow admits max minus one and rolls back max mutations including subtree peers', function (): void {
    $top = $this->drive->folders()->create('top');
    $child = $this->drive->folders()->create('child', $top);
    app(VaulterDatabase::class)->write(fn () => Node::query()->whereKey($child->id)->update(['sequence' => PHP_INT_MAX - 1]));
    expect($this->drive->nodes()->rename($child, 'last name', PHP_INT_MAX - 1)->sequence)->toBe(PHP_INT_MAX);
    $before = sequenceContractState();
    expect(fn () => $this->drive->nodes()->rename($child, 'overflow', PHP_INT_MAX))->toThrow(SequenceOverflowException::class)
        ->and(sequenceContractState())->toBe($before)
        ->and(fn () => $this->drive->nodes()->trash($top, 0))->toThrow(SequenceOverflowException::class)
        ->and(sequenceContractState())->toBe($before);
});

it('SEC-R20-overflow rejects negative expectations and corrupted negative helper input', function (): void {
    $node = $this->drive->folders()->create('node');
    expect(fn () => $this->drive->nodes()->rename($node, 'invalid', -1))->toThrow(InvalidArgumentException::class);
    $db = app(VaulterDatabase::class);
    expect(fn () => $db->transaction(function () use ($node): void {
        $locked = Node::query()->whereKey($node->id)->lockForUpdate()->firstOrFail();
        $locked->forceFill(['sequence' => -1]);
        app(NodeSequence::class)->bump($locked);
    }))->toThrow(SequenceOverflowException::class);
    expect(DriveTestHelpers::model($node)->sequence)->toBe(0)->and($node->name)->toBe('node');
});

it('SEC-R19-replay returns the committed result without ingesting or comparing the old expectation again', function (): void {
    DriveTestHelpers::insideOperation(); // drives the S3 handler and the manager directly
    $node = $this->drive->files()->upload(UploadSource::fromString('original', 'replay.txt'));
    app(VaulterDatabase::class)->write(fn () => Node::query()->whereKey($node->id)->update(['sequence' => 7]));
    $bytes = 'committed session bytes';
    $manager = app(UploadSessionManager::class);
    $session = $manager->initiate(context: DriveContext::system($this->drive->model(), 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::S3Multipart,
        targetNode: $node, targetName: 'replay.txt', totalSizeBytes: strlen($bytes),
        mimeType: 'text/plain', expectedSequence: 7,
    ));
    $handler = app(S3MultipartHandler::class);
    $chunk = DriveTestHelpers::streamFor($bytes);
    try {
        $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: $chunk);
    } finally {
        fclose($chunk);
    }
    $handler->complete($session->refresh());
    $manager->markAssembled($session->refresh());
    $context = DriveContext::system($this->drive->model(), 'session replay acceptance');
    $complete = app(CompleteUploadAction::class);
    $commit = static fn (UploadSession $locked): Node => $complete->execute(context: $context, session: $locked);
    $committed = $manager->commitAssembled($session->refresh(), $commit);
    $session->refresh();
    expect($committed->sequence)->toBe(8)->and($session->status)->toBe(UploadStatus::Committed)
        ->and((string) $session->result_node_id)->toBe($node->id)
        ->and($session->result_revision_id)->toBe($committed->file()->value('current_revision_id'))
        ->and($session->input_fingerprint)->toBeString();
    $resultRevision = $session->result_revision_id;
    $this->drive->nodes()->rename($node, 'intervening rename.txt', 8);
    // The suite wraps the transaction, so use the real cleanup seam to reproduce
    // the missing staged bytes a replay encounters after the outer commit.
    $staged = app(StagedUpload::class);
    $staged->discard($session);
    expect($staged->diskFor($session)->exists($staged->keyFor($session)))->toBeFalse();
    $before = sequenceContractState();
    $stored = $session->getAttributes();
    $replayed = $manager->commitAssembled($session->refresh(), $commit);
    expect((string) $replayed->id)->toBe($node->id)->and($replayed->sequence)->toBe(9)
        ->and($session->refresh()->result_revision_id)->toBe($resultRevision)
        ->and($session->getAttributes())->toBe($stored)
        ->and(sequenceContractState())->toBe($before);

    $session->forceFill(['target_name' => 'different persisted payload.txt'])->save();
    expect(fn () => $manager->commitAssembled($session->refresh(), $commit))->toThrow(UploadConflictException::class)
        ->and(sequenceContractState())->toBe($before)->and(DriveTestHelpers::model($node)->sequence)->toBe(9);
});
