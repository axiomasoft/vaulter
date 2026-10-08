<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\StreamInterface;
use Vaulter\Data\Drive\RevisionOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Events\Files\FileRevisionAdded;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Exceptions\Nodes\StaleNodeException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Concurrency\ForkRace;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    Storage::fake('local');
});

it('SEC-R18 forks expected 7 rename against content with one sequence 8 winner and a side-effect-free 412 loser', function (): void {
    $owner = DriveTestHelpers::host();
    $drive = Vaulter::drive($owner)->asSystem('sequence race fixture');
    $node = $drive->files()->upload(UploadSource::fromString('old', 'race.txt'));
    Node::query()->whereKey($node->id)->update(['sequence' => 7]);
    $nodeId = $node->id;
    $db = app(VaulterDatabase::class)->connection();
    $before = [];
    foreach (['v_nodes', 'v_files', 'v_file_revisions', 'v_blobs', 'v_file_revision_blobs', 'v_activity', 'v_outbox'] as $table) {
        $before[$table] = $db->table($table)->count();
    }

    $outcomes = ForkRace::run(array_map(static fn (string $operation): Closure => static function () use ($owner, $nodeId, $operation): array {
        $connection = app(VaulterDatabase::class)->connection();
        $backend = (int) $connection->selectOne('select pg_backend_pid() as pid')->pid;
        $events = [];
        Event::listen('Vaulter\\Drive\\Events\\*', static function (string $name) use (&$events): void {
            $events[] = $name;
        });
        $handle = Vaulter::drive($owner)->asSystem('sequence race contender');
        try {
            if ($operation === 'rename') {
                $handle->nodes()->rename($nodeId, 'winner.txt', 7);
            } else {
                $handle->files()->addRevision($nodeId, UploadSource::fromString('new longer bytes', 'race.txt'), new RevisionOptions(expectedSequence: 7));
            }

            return ['process' => getmypid(), 'backend' => $backend, 'operation' => $operation, 'ok' => true, 'events' => $events];
        } catch (StaleNodeException $exception) {
            return ['process' => getmypid(), 'backend' => $backend, 'operation' => $operation, 'ok' => false, 'exception' => $exception::class, 'status' => $exception->httpStatus(), 'events' => $events];
        }
    }, ['rename', 'content']));

    foreach ($outcomes as $outcome) {
        expect($outcome->ok)->toBeTrue($outcome->message ?? '');
    }
    $values = array_map(static fn ($outcome): array => $outcome->value, $outcomes);
    expect(array_unique(array_column($values, 'process')))->toHaveCount(2)
        ->and(array_unique(array_column($values, 'backend')))->toHaveCount(2);
    $winner = array_values(array_filter($values, static fn (array $value): bool => $value['ok']));
    $loser = array_values(array_filter($values, static fn (array $value): bool => !$value['ok']));
    expect($winner)->toHaveCount(1)->and($loser)->toHaveCount(1)
        ->and($loser[0]['exception'])->toBe(StaleNodeException::class)
        ->and($loser[0]['status'])->toBe(412)->and($loser[0]['events'])->toBe([])
        ->and($winner[0]['events'])->toHaveCount(1)
        ->and(DriveTestHelpers::model($node)->sequence)->toBe(8);
    $contentWon = $winner[0]['operation'] === 'content';
    $expectedSize = $contentWon ? strlen('new longer bytes') : strlen('old');
    expect($node->name)->toBe($contentWon ? 'race.txt' : 'winner.txt')
        ->and($node->sizeBytes)->toBe($expectedSize)
        // Every retained revision counts (PLAN2.D48).
        ->and($drive->model()->refresh()->used_bytes)->toBe($contentWon ? strlen('old') + strlen('new longer bytes') : strlen('old'))
        ->and(DriveTestHelpers::model($node)->file()->firstOrFail()->revision_count)->toBe($contentWon ? 2 : 1);
    $db = app(VaulterDatabase::class)->connection();
    foreach (['v_nodes', 'v_files'] as $table) {
        expect($db->table($table)->count())->toBe($before[$table]);
    }
    foreach (['v_file_revisions', 'v_blobs', 'v_file_revision_blobs'] as $table) {
        expect($db->table($table)->count())->toBe($before[$table] + (int) $contentWon);
    }
    expect(FileRevision::query()->where('file_id', DriveTestHelpers::model($node)->file()->value('id'))->orderBy('number')->pluck('number')->all())
        ->toBe($contentWon ? [1, 2] : [1]);
    foreach (['v_activity', 'v_outbox'] as $table) {
        expect($db->table($table)->count() - $before[$table])->toBeLessThanOrEqual(1);
    }
});

it('SEC-R19-retry preserves the original expectation after serialization retry', function (): void {
    $drive = Vaulter::drive(DriveTestHelpers::host())->asSystem('retry fixture');
    $node = $drive->folders()->create('original');
    Node::query()->whereKey($node->id)->update(['sequence' => 7]);
    $connection = app(VaulterDatabase::class)->connection();
    $failed = false;
    $advanced = false;
    $connection->listen(static function ($query) use (&$failed): void {
        if (!$failed && str_starts_with($query->sql, 'update "v_nodes"') && str_contains($query->sql, '"name"')) {
            $failed = true;
            throw new PDOException('serialization failure', 40001);
        }
    });
    $connection->beforeExecuting(static function (string $sql) use ($connection, $node, &$failed, &$advanced): void {
        if ($failed && !$advanced && str_starts_with($sql, 'select') && str_contains($sql, '"v_nodes"')) {
            $advanced = true;
            // Model another committed writer before the retry re-reads the locked row.
            $statement = $connection->getPdo()->prepare('update v_nodes set sequence = 8 where id = ?');
            $statement->execute([$node->id]);
        }
    });
    expect(fn () => $drive->nodes()->rename($node, 'retry overwrite', 7))->toThrow(StaleNodeException::class)
        ->and($failed)->toBeTrue()->and($advanced)->toBeTrue()
        ->and(DriveTestHelpers::model($node)->name)->toBe('original');
});

it('SEC-REV-RECEIPT returns its own committed revision when a later writer changes the head before return', function (): void {
    $handle = Vaulter::drive(DriveTestHelpers::host())->asSystem('revision receipt fixture');
    $node = $handle->files()->upload(UploadSource::fromString('initial', 'receipt.txt'));
    $fileId = $node->id;
    $later = null;
    Event::listen(FileRevisionAdded::class, function ($event) use ($handle, $fileId, &$later): void {
        if ($event->version->number !== 2) {
            return;
        }
        app(VaulterDatabase::class)->afterCommit(function () use ($handle, $fileId, &$later): void {
            $later = $handle->files()->addRevision($fileId, UploadSource::fromString('later bytes', 'receipt.txt'), new RevisionOptions(expectedSequence: 1));
        });
    });

    $receipt = $handle->files()->addRevision($fileId, UploadSource::fromString('my bytes', 'receipt.txt'), new RevisionOptions(expectedSequence: 0));

    expect($later)->toBeInstanceOf(FileRevision::class)
        ->and($receipt->number)->toBe(2)
        ->and($receipt->sha256)->toBe(hash('sha256', 'my bytes'))
        ->and($later->number)->toBe(3)
        ->and($later->sha256)->toBe(hash('sha256', 'later bytes'))
        ->and($receipt->id)->not->toBe($later->id)
        ->and(DriveTestHelpers::model($node)->file()->value('current_revision_id'))->toBe($later->id)
        ->and($node->sequence)->toBe(2)
        ->and(FileRevision::query()->count())->toBe(3);
});

it('SEC-CONFLICT-RACE turns two locked same-name uploads into one node and two revisions from nonseekable sources', function (): void {
    $owner = DriveTestHelpers::host();
    $handle = Vaulter::drive($owner)->asSystem('AddRevision race fixture');
    $handle->model()->forceFill(['quota_bytes' => 6])->save(); // both revisions are retained and counted (PLAN2.D48)
    $barrier = sys_get_temp_dir().'/vaulter_addrevision_'.bin2hex(random_bytes(8));
    mkdir($barrier);
    try {
        $outcomes = ForkRace::run(array_map(static fn (string $bytes): Closure => static function () use ($owner, $barrier, $bytes): array {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair === false) {
                throw new RuntimeException('Unable to open the race source.');
            }
            [$reader, $writer] = $pair;
            fwrite($writer, $bytes);
            fclose($writer);
            $stream = new class(Utils::streamFor($reader), $barrier) implements StreamInterface
            {
                use StreamDecoratorTrait;

                private bool $arrived = false;

                public function __construct(StreamInterface $stream, private string $barrier)
                {
                    $this->stream = $stream;
                }

                public function read($length): string
                {
                    if (!$this->arrived) {
                        $this->arrived = true;
                        file_put_contents($this->barrier.'/'.getmypid(), 'ready');
                        $deadline = hrtime(true) + 5_000_000_000;
                        while (count(glob($this->barrier.'/*') ?: []) !== 2) {
                            if (hrtime(true) > $deadline) {
                                throw new RuntimeException('Source barrier timed out.');
                            }
                            usleep(1000);
                        }
                    }

                    return $this->stream->read($length);
                }
            };
            try {
                $node = Vaulter::drive($owner)->asSystem('AddRevision contender')->files()->upload(
                    UploadSource::fromPsrStream($stream, 'same.txt'),
                    new UploadOptions(onConflict: OnNameConflict::AddRevision),
                );

                return ['id' => $node->id, 'sha' => $node->file->currentRevision->sha256, 'expected_sha' => hash('sha256', $bytes), 'source_open' => $stream->isReadable(), 'nonseekable' => !$stream->isSeekable(), 'pid' => getmypid(), 'backend' => app(VaulterDatabase::class)->connection()->selectOne('select pg_backend_pid() as pid')->pid];
            } finally {
                $stream->close();
            }
        }, ['aaa', 'bbb']));
        foreach ($outcomes as $outcome) {
            expect($outcome->ok)->toBeTrue($outcome->message ?? '');
            expect($outcome->value['sha'])->toBe($outcome->value['expected_sha'])
                ->and($outcome->value['source_open'])->toBeTrue()
                ->and($outcome->value['nonseekable'])->toBeTrue();
        }
        $values = array_map(static fn ($outcome): array => $outcome->value, $outcomes);
        expect(array_unique(array_column($values, 'pid')))->toHaveCount(2)
            ->and(array_unique(array_column($values, 'backend')))->toHaveCount(2)
            ->and(array_unique(array_column($values, 'id')))->toHaveCount(1)
            ->and(Node::query()->count())->toBe(2)
            ->and(FileRevision::query()->orderBy('number')->pluck('number')->all())->toBe([1, 2])
            ->and($handle->nodes()->find($values[0]['id'])->sequence)->toBe(1)
            ->and($handle->model()->used_bytes)->toBe(6);
    } finally {
        foreach (glob($barrier.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($barrier);
    }
});
