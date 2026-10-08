<?php

declare(strict_types=1);

use Illuminate\Pagination\Cursor;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Drive\NodeSort;
use Vaulter\Enums\Events\EventType;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\ActivityOperations;
use Vaulter\Operations\OperationContext;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\ValueObjects\Access\Actor;

beforeEach(function (): void {
    config()->set('vaulter.access.permission_driver', 'database');
    $this->drive = AccessTestHelpers::storage();
    $this->root = $this->drive->rootNode()->firstOrFail();
    AccessTestHelpers::grant($this->root, RoleRegistry::READER, scope: GrantScope::Item);
    $this->context = new OperationContext($this->drive, Actor::user(AccessTestHelpers::user()), 'visibility');
    $this->operation = app()->makeWith(ActivityOperations::class, ['context' => $this->context]);
    Activity::query()->delete();
});

function visibleActivityRow(Node $node, int $order): Activity
{
    $row = (new Activity)->forceFill(['drive_id' => $node->drive_id, 'target_type' => $node->getMorphClass(), 'target_id' => $node->id, 'verb' => EventType::NodeRenamed, 'created_at' => now()->startOfSecond()->addSeconds($order)]);
    $row->save();

    return $row;
}

it('does not expose hidden unknown deleted or foreign targets under root View', function (): void {
    $hidden = AccessTestHelpers::folder($this->drive, null, 'hidden');
    visibleActivityRow($hidden, 1);
    $deleted = AccessTestHelpers::folder($this->drive, null, 'deleted');
    visibleActivityRow($deleted, 2);
    $deleted->delete();
    $unknown = (new Activity)->forceFill(['drive_id' => $this->drive->id, 'verb' => EventType::NodePurged, 'context' => ['name' => 'secret'], 'created_at' => now()]);
    $unknown->save();
    $other = AccessTestHelpers::folder(AccessTestHelpers::storage(), null, 'foreign');
    $foreign = visibleActivityRow($other, 3);
    $foreign->forceFill(['drive_id' => $this->drive->id])->save();
    expect($this->operation->forDrive()->items())->toBe([]);
    $system = app()->makeWith(ActivityOperations::class, ['context' => OperationContext::system($this->drive, 'audit')]);
    expect($system->forDrive()->items())->toHaveCount(4);
});

it('fills pages across hidden windows and retains stable tuple ties', function (): void {
    $visible = AccessTestHelpers::folder($this->drive, null, 'visible');
    $hidden = AccessTestHelpers::folder($this->drive, null, 'hidden');
    AccessTestHelpers::grant($visible, RoleRegistry::READER);
    $expected = [];
    foreach (range(1, 6) as $i) {
        $expected[] = visibleActivityRow($visible, 1)->id;
    }
    foreach (range(1, 60) as $i) {
        visibleActivityRow($hidden, 2);
    }
    $page = $this->operation->forDrive(new ListOptions(perPage: 3));
    expect($page->items())->toHaveCount(3)->and($page->previousCursor())->toBeNull();
    $next = $this->operation->forDrive(new ListOptions(perPage: 3, cursor: $page->nextCursor()->encode()));
    expect($next->items())->toHaveCount(3)->and(array_intersect(array_column($page->items(), 'id'), array_column($next->items(), 'id')))->toBe([])
        ->and($next->nextCursor())->toBeNull();
});

it('continues from last scanned tuple even for an empty budget-limited page', function (): void {
    config()->set('vaulter.listing.scan_budget', 2);
    $visible = AccessTestHelpers::folder($this->drive, null, 'visible');
    $hidden = AccessTestHelpers::folder($this->drive, null, 'hidden');
    AccessTestHelpers::grant($visible, RoleRegistry::READER);
    $row = visibleActivityRow($visible, 1);
    visibleActivityRow($hidden, 2);
    visibleActivityRow($hidden, 3);
    $page = $this->operation->forDrive();
    expect($page->items())->toBe([])->and($page->nextCursor())->not->toBeNull();
    $next = $this->operation->forDrive(new ListOptions(cursor: $page->nextCursor()->encode()));
    expect($next->items())->toHaveCount(1)->and($next->items()[0]->id)->toBe($row->id);
});

it('rejects tampered cross-scope and raw cursors and rechecks ACL across pages', function (): void {
    $node = AccessTestHelpers::folder($this->drive, null, 'live');
    $grant = AccessTestHelpers::grant($node, RoleRegistry::READER);
    visibleActivityRow($node, 1);
    visibleActivityRow($node, 2);
    $page = $this->operation->forDrive(new ListOptions(perPage: 1));
    $cursor = $page->nextCursor();
    expect(fn () => $this->operation->forNode($node, new ListOptions(cursor: $cursor->encode())))->toThrow(InvalidArgumentException::class);
    $params = $cursor->toArray();
    $params['id'] = 'forged';
    $tampered = new Cursor($params);
    expect(fn () => $this->operation->forDrive(new ListOptions(cursor: $tampered->encode())))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->operation->forDrive(new ListOptions(cursor: 'invalid')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->operation->forDrive(new ListOptions(sort: NodeSort::Name)))->toThrow(InvalidArgumentException::class);
    $grant->delete();
    expect($this->operation->forDrive(new ListOptions(cursor: $cursor->encode()))->items())->toBe([]);
});

it('SEC-I7-projection withholds request fingerprint and access details from a reader without Share', function (): void {
    $readOnly = AccessTestHelpers::folder($this->drive, null, 'read only');
    $managed = AccessTestHelpers::folder($this->drive, null, 'managed');
    AccessTestHelpers::grant($readOnly, RoleRegistry::READER);
    AccessTestHelpers::grant($managed, RoleRegistry::ORGANIZER);
    $details = static fn (): array => [
        'ip' => '203.0.113.9', 'user_agent' => 'curl/8', 'request_id' => 'req-1', 'actor_type' => 'user', 'actor_id' => '42',
        'context' => ['subject_type' => 'user', 'subject_id' => '7', 'permissions' => 5, 'token_prefix' => 'abcd1234', 'level' => 'reader'],
    ];
    $rows = [];
    foreach ([$readOnly, $managed] as $index => $node) {
        $rows[(string) $node->id] = visibleActivityRow($node, $index + 1)->forceFill($details());
        $rows[(string) $node->id]->save();
    }
    $byTarget = static fn (array $items): array => array_combine(array_map(static fn (Activity $row): string => (string) $row->target_id, $items), $items);

    $seen = $byTarget($this->operation->forDrive()->items());

    $restricted = $seen[(string) $readOnly->id];
    expect($restricted->ip)->toBeNull()->and($restricted->user_agent)->toBeNull()->and($restricted->request_id)->toBeNull()
        ->and($restricted->actor_id)->toBeNull()->and($restricted->actor_type)->toBe('user')
        ->and($restricted->context)->toBe(['level' => 'reader']);
    $full = $seen[(string) $managed->id];
    expect($full->ip)->toBe('203.0.113.9')->and($full->actor_id)->toBe('42')->and($full->context)->toHaveKeys(['subject_id', 'permissions', 'token_prefix']);

    $system = app()->makeWith(ActivityOperations::class, ['context' => OperationContext::system($this->drive, 'audit')]);
    foreach ($system->forDrive()->items() as $row) {
        expect($row->ip)->toBe('203.0.113.9')->and($row->context)->toHaveKey('subject_id');
    }
    // The projection is read-only: nothing was written back.
    expect(Activity::query()->findOrFail($rows[(string) $readOnly->id]->id)->ip)->toBe('203.0.113.9');
    $restricted->forceFill(['verb' => EventType::NodeRenamed])->save();
    expect(Activity::query()->findOrFail($restricted->id)->ip)->toBe('203.0.113.9');
});
