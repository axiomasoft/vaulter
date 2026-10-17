<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Str;
use Vaulter\Collaboration\Persistence\UpdateEnvelope;
use Vaulter\Collaboration\Persistence\UpdateLog;
use Vaulter\Collaboration\Tests\Collaboration\HubTestHelpers as Hub;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Models\Node;

/*
 * C1–C3 on MySQL (REPEATABLE READ): the transaction of an append or a load begins with a plain read (the node, in
 * CollaborativeState::lock()), which fixes its snapshot. Every read of the log and the document after the locks must be a
 * locking read, or it answers from that older snapshot: a load would report a head without its tail, and a retry of an
 * update id committed meanwhile would miss its receipt. SQLite drops the lock clause, so the queries are rendered through
 * the MySQL grammar as they run here; the run itself is a PLAN4 line (AppendRaceTest).
 */

function lockingAppend(Node $node, string $payload, string $updateId): void
{
    app(UpdateLog::class)->append($node->getKey(), new UpdateEnvelope(
        generation: Hub::aggregate($node)->collab_epoch,
        updateId: $updateId,
        payload: $payload,
        claimedSha256: hash('sha256', $payload),
    ));
}

/**
 * The selects `$run` issues on the log tables and v_documents, as MySQL would receive them.
 *
 * @return array<string, list<string>> by entity
 */
function lockingSelects(Closure $run): array
{
    $connection = app(VaulterDatabase::class)->connection();
    $recorder = new class($connection, new MySqlGrammar($connection)) extends SQLiteGrammar
    {
        /** @var list<string> */
        public array $selects = [];

        public function __construct(Connection $connection, private readonly MySqlGrammar $mysql)
        {
            parent::__construct($connection);
        }

        public function compileSelect(Builder $query)
        {
            $this->selects[] = $this->mysql->compileSelect($query);

            return parent::compileSelect($query);
        }
    };

    $connection->setQueryGrammar($recorder);
    try {
        $run();
    } finally {
        $connection->useDefaultQueryGrammar();
    }

    $tables = app(DatabaseConfig::class);
    $selects = [];
    foreach (['documents', 'collaboration_heads', 'collaboration_updates', 'collaboration_receipts'] as $entity) {
        $from = 'from `'.$tables->table($entity).'`';
        $selects[$entity] = array_values(array_filter($recorder->selects, static fn (string $sql): bool => str_contains($sql, $from)));
    }

    return $selects;
}

beforeEach(function (): void {
    Hub::configure();
    $this->node = Hub::document();
});

it('reads the receipt of an appended update id with a locking read, on a first append and on a retry', function (): void {
    $id = (string) Str::uuid();

    $first = lockingSelects(fn () => lockingAppend($this->node, 'update', $id));
    $retry = lockingSelects(fn () => lockingAppend($this->node, 'update', $id));

    foreach ([$first, $retry] as $selects) {
        expect($selects['collaboration_receipts'])->toHaveCount(1)
            ->and($selects['collaboration_receipts'][0])->toEndWith('for update')
            ->and($selects['collaboration_heads'])->not->toBeEmpty()
            ->each->toEndWith('for update');
    }
});

it('reads the document row and the tail of a load with locking reads', function (): void {
    lockingAppend($this->node, 'first', (string) Str::uuid());
    lockingAppend($this->node, 'second', (string) Str::uuid());

    $snapshot = null;
    $selects = lockingSelects(function () use (&$snapshot): void {
        $snapshot = app(UpdateLog::class)->snapshot($this->node->getKey(), 0);
    });

    expect($snapshot->headSeq)->toBe(2)
        ->and($snapshot->tail)->toHaveCount(2)
        ->and($selects['documents'])->not->toBeEmpty()
        ->each->toEndWith('for update')
        ->and($selects['collaboration_heads'])->not->toBeEmpty()
        ->each->toEndWith('for update')
        ->and($selects['collaboration_updates'])->toHaveCount(1)
        ->and($selects['collaboration_updates'][0])->toEndWith('for update');
});
