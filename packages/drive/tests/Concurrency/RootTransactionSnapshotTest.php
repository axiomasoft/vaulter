<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\NodeName;

// P3.10: the operation's root transaction opens before the attempt reads anything, so on MySQL
// (REPEATABLE READ) a read made before the tree lock must not freeze the sibling check taken after it.
// A peer session commits the conflicting name in between; the create has to see it.
// Committed rows (DatabaseMigrations, see tests/Pest.php): the peer session must see the fixtures.

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('The snapshot taken before the tree lock is a MySQL REPEATABLE READ effect.');
    }
    config()->set('database.connections.snapshot_peer', config('database.connections.'.DB::getDefaultConnection()));
});

afterEach(function (): void {
    DB::purge('snapshot_peer');
});

it('sees a sibling name committed by a peer between the first read and the tree lock', function (): void {
    $system = Vaulter::drive(DriveTestHelpers::host())->asSystem('snapshot fixture');
    $parent = $system->folders()->create('parent');
    $other = $system->folders()->create('other', $parent);
    $table = (new Node)->getTable();
    $peer = DB::connection('snapshot_peer');
    $raced = false;

    Node::retrieved(function (Node $model) use ($parent, $other, $table, $peer, &$raced): void {
        if ($raced || (string) $model->id !== (string) $parent->id || DB::connection()->transactionLevel() < 1) {
            return;
        }
        $raced = true;
        $peer->table($table)->where('id', $other->id)->update(['name' => 'taken', 'name_key' => NodeName::key('taken')]);
    });

    expect(fn () => $system->folders()->create('taken', $parent))->toThrow(NodeNameConflictException::class);
    expect($raced)->toBeTrue()
        ->and(Node::query()->where('parent_id', $parent->id)->where('name_key', NodeName::key('taken'))->count())->toBe(1);
});
