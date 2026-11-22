<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Contracts\AtomicDocumentMutation;
use Vaulter\Documents\Contracts\LockedDocumentMutation;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Documents\Tests\Concurrency\RaceFixtures;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Fixtures\GrantedDocuments;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Actions\Access\RevokeNodeAccessAction;
use Vaulter\Drive\Data\Access\RevokeGrantInput;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Testing\Concurrency\ForkRace;

beforeEach(function (): void {
    $this->connection = app(VaulterDatabase::class)->connection();
    ForkRace::skipUnlessSupported($this, $this->connection->getName());
    GrantedDocuments::configure();

    $this->connection->getSchemaBuilder()->create('atomic_race_receipts', static function (Blueprint $table): void {
        $table->string('command_key')->primary();
        $table->string('receipt');
    });
    $this->barrier = sys_get_temp_dir().'/vlt_adm_'.bin2hex(random_bytes(8));
    mkdir($this->barrier);
});

afterEach(function (): void {
    if (isset($this->barrier)) {
        array_map(unlink(...), glob($this->barrier.'/*') ?: []);
        rmdir($this->barrier);
        app(VaulterDatabase::class)->connection()->getSchemaBuilder()->dropIfExists('atomic_race_receipts');
    }
});

function waitForAtomicMutationRace(Closure $condition): void
{
    $deadline = hrtime(true) + 8_000_000_000;
    while (!$condition()) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the atomic mutation lock barrier.');
        }
        usleep(2_000);
    }
}

function atomicMutationHolderWaitsForWriter(string $barrier, Connection $connection): void
{
    touch($barrier.'/holding');
    waitForAtomicMutationRace(static fn (): bool => is_file($barrier.'/waiting'));
    $pid = (int) file_get_contents($barrier.'/waiting');
    waitForAtomicMutationRace(static fn (): bool => (bool) $connection->scalar(
        'select pg_backend_pid() = any(pg_blocking_pids(?))', [$pid],
    ));
}

function atomicMutationWriterWaitsForHolder(string $barrier): void
{
    waitForAtomicMutationRace(static fn (): bool => is_file($barrier.'/holding'));
    $pid = app(VaulterDatabase::class)->connection()->scalar('select pg_backend_pid()');
    file_put_contents($barrier.'/pid', (string) $pid);
    rename($barrier.'/pid', $barrier.'/waiting');
}

it('waits on N and refuses the writer after the revoke commits without locking D', function (): void {
    $node = GrantedDocuments::document(level: null);
    $grant = AccessTestHelpers::grant($node, RoleRegistry::WRITER, subjectId: 1);
    $system = DocumentTestHelpers::context($node);
    $editor = DocumentTestHelpers::context($node, 1);
    $documentTable = GrantedDocuments::aggregate($node)->getTable();
    $barrier = $this->barrier;

    $results = RaceFixtures::race([
        static fn (): array => RaceFixtures::attempt(static function () use ($system, $node, $grant, $barrier): void {
            app(VaulterDatabase::class)->transaction(static function (Connection $connection) use ($system, $node, $grant, $barrier): void {
                app(RevokeNodeAccessAction::class)->execute($system, new RevokeGrantInput($node, $grant));
                atomicMutationHolderWaitsForWriter($barrier, $connection);
            });
        }),
        static function () use ($editor, $node, $barrier, $documentTable): array {
            $documentLocked = false;
            app(VaulterDatabase::class)->connection()->listen(static function (QueryExecuted $query) use (&$documentLocked, $documentTable): void {
                if (str_contains($query->sql, '"'.$documentTable.'"') && str_contains(strtolower($query->sql), 'for update')) {
                    $documentLocked = true;
                }
            });
            $result = RaceFixtures::attempt(static function () use ($editor, $node, $barrier): void {
                atomicMutationWriterWaitsForHolder($barrier);
                app(AtomicDocumentMutation::class)->run($editor, $node->id, static function (LockedDocumentMutation $locked): void {
                    $locked->replace(DocumentTestHelpers::content('After revoke'), $locked->snapshot()->position);
                    $locked->connection()->table('atomic_race_receipts')->insert(['command_key' => 'revoked', 'receipt' => 'invalid']);
                });
            });

            return $result + ['documentLocked' => $documentLocked];
        },
    ]);

    expect($results[0]['kind'])->toBe('committed')
        ->and($results[1]['kind'])->toBe('node_not_found')
        ->and($results[1]['status'])->toBe(404)
        ->and($results[1]['documentLocked'])->toBeFalse()
        ->and($results[1]['events'])->toBe(0)
        ->and(GrantedDocuments::aggregate($node)->state_version)->toBe(1)
        ->and(OutboxEvent::query()->where('type', DocumentEventType::DocumentUpdated->value)->count())->toBe(0)
        ->and(app(VaulterDatabase::class)->connection()->table('atomic_race_receipts')->count())->toBe(0);
});

it('commits exactly one replacement and receipt for a shared key when the first writer :dataset', function (string $outcome): void {
    $node = GrantedDocuments::document();
    $context = DocumentTestHelpers::context($node, 1);
    $barrier = $this->barrier;
    $abort = new RuntimeException('Roll back the first writer');
    $command = static function (string $writer, ?Closure $beforeReturn = null) use ($node, $context): string {
        return app(AtomicDocumentMutation::class)->run($context, $node->id, static function (LockedDocumentMutation $locked) use ($writer, $beforeReturn): string {
            $connection = $locked->connection();
            $receipt = $connection->table('atomic_race_receipts')->where('command_key', 'same-key')->value('receipt');
            if (is_string($receipt)) {
                return $receipt;
            }

            $locked->replace(DocumentTestHelpers::content($writer), $locked->snapshot()->position);
            $connection->table('atomic_race_receipts')->insert(['command_key' => 'same-key', 'receipt' => $writer]);
            if ($beforeReturn !== null) {
                $beforeReturn($connection);
            }

            return $writer;
        });
    };

    $results = RaceFixtures::race([
        static function () use ($command, $outcome, $barrier, $abort): array {
            $receipt = null;
            $result = RaceFixtures::attempt(static function () use ($command, $outcome, $barrier, $abort, &$receipt): void {
                try {
                    $receipt = $command('first', static function (Connection $connection) use ($outcome, $barrier, $abort): void {
                        atomicMutationHolderWaitsForWriter($barrier, $connection);
                        if ($outcome === 'rolls back') {
                            throw $abort;
                        }
                    });
                } catch (RuntimeException $error) {
                    if ($error !== $abort) {
                        throw $error;
                    }
                }
            });

            return $result + ['receipt' => $receipt];
        },
        static function () use ($command, $barrier): array {
            $receipt = null;
            $result = RaceFixtures::attempt(static function () use ($command, $barrier, &$receipt): void {
                atomicMutationWriterWaitsForHolder($barrier);
                $receipt = $command('second');
            });

            return $result + ['receipt' => $receipt];
        },
    ]);

    $committedFirst = $outcome === 'commits';
    $winner = $committedFirst ? 'first' : 'second';
    $document = GrantedDocuments::aggregate($node);
    expect(array_column($results, 'kind'))->toBe(['committed', 'committed'])
        ->and(array_column($results, 'events'))->toBe($committedFirst ? [1, 0] : [0, 1])
        ->and(array_column($results, 'receipt'))->toBe([$committedFirst ? $winner : null, $winner])
        ->and(app(VaulterDatabase::class)->connection()->table('atomic_race_receipts')->pluck('receipt')->all())->toBe([$winner])
        ->and($document->state_version)->toBe(2)
        ->and($document->payload?->content)->toEqual(GrantedDocuments::tiptap($winner))
        ->and(OutboxEvent::query()->where('type', DocumentEventType::DocumentUpdated->value)->count())->toBe(1);
})->with(['commits', 'rolls back']);

it('preserves an ordinary save to a neighboring paragraph between prepare and commit', function (): void {
    $paragraph = static fn (string $text): array => GrantedDocuments::tiptap($text)['content'][0];
    $prepare = ['type' => 'doc', 'content' => [$paragraph('target'), $paragraph('neighbor')]];
    $node = GrantedDocuments::document(content: TipTapContent::fromArray($prepare));
    $context = DocumentTestHelpers::context($node, 1);
    $port = app(AtomicDocumentMutation::class);
    $prepared = $port->run($context, $node->id, static fn (LockedDocumentMutation $locked) => $locked->snapshot());

    $human = $prepare;
    $human['content'][1] = $paragraph('human edit');
    $result = RaceFixtures::race([
        static fn (): array => RaceFixtures::attempt(static fn () => app(UpdateDocumentContentAction::class)->execute(
            $context, new UpdateDocumentContentInput(node: $node, content: $human, expectedStateVersion: $prepared->position),
        )),
    ]);

    $saved = $port->run($context, $node->id, static function (LockedDocumentMutation $locked) use ($paragraph, $prepared) {
        $current = $locked->snapshot();
        $content = $current->content->toArray();
        expect($current->position)->toBe($prepared->position + 1)
            ->and($content['content'][0])->toEqual($prepared->content->toArray()['content'][0]);
        $content['content'][0] = $paragraph('command edit');

        return $locked->replace(TipTapContent::fromArray($content), $current->position);
    });

    expect($result[0]['kind'])->toBe('committed')
        ->and($saved->position)->toBe(3)
        ->and($saved->content->toArray()['content'])->toEqual([$paragraph('command edit'), $paragraph('human edit')]);
});
