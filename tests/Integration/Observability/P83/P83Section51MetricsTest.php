<?php

declare(strict_types=1);

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Actions\Nodes\MoveNodeAction;
use Vaulter\Drive\Data\Nodes\MoveNodeInput;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Exceptions\Nodes\NodeCycleException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Http\Middleware\RecordVaulterRequestMetrics;
use Vaulter\Load\Listeners\RecordBroadcastQueueWait;
use Vaulter\Load\Observability\CollaborationRoomMetrics;
use Vaulter\Support\LoadTier;
use Vaulter\Testing\FakeMetricsRecorder;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\ValueObjects\Access\Actor;

beforeEach(function (): void {
    config()->set('vaulter.observability.enabled', true);
    config()->set('vaulter.observability.recorder', FakeMetricsRecorder::class);

    $this->app->forgetInstance(MetricsRecorder::class);
});

it('records vaulter_http_request_duration_seconds for mapped routes', function (): void {
    LoadTier::set('S');

    $middleware = app(RecordVaulterRequestMetrics::class);
    $request = Request::create('/api/vaulter/v1/drives/1/nodes', 'GET');
    // A versioned request is labelled by its matched route name.
    $request->setRouteResolver(static fn () => app('router')->getRoutes()->getByName('vaulter.v1.nodes.index'));
    $request->attributes->set(LoadTier::REQUEST_ATTRIBUTE, 'S');

    $middleware->handle($request, static fn (): Response => new Response('', 200));

    $recorder = app(MetricsRecorder::class);
    expect($recorder)->toBeInstanceOf(FakeMetricsRecorder::class);
    $recorder->assertHistogram(
        name: 'vaulter_http_request_duration_seconds',
        labels: ['operation' => 'tree_list', 'tier' => 'S', 'outcome' => 'success'],
    );
});

it('records vaulter_tree_query_duration_seconds for search queries', function (): void {
    LoadTier::set('M');

    $storage = DriveTestHelpers::drive()->model();
    app(NodeReadRepository::class)->search($storage, 'needle');

    app(MetricsRecorder::class)->assertHistogram(
        name: 'vaulter_tree_query_duration_seconds',
        labels: ['query_kind' => 'search', 'tier' => 'M'],
    );
});

it('records vaulter_tree_cte_anchors when grant filtering resolves subtree anchors', function (): void {
    LoadTier::set('S');

    config()->set('vaulter-drive.features.grants', true);
    config()->set('vaulter-drive.access.permission_driver', 'database');

    app()->forgetInstance(NodeReadRepository::class);

    $storage = AccessTestHelpers::storage();
    $root = $storage->rootNode()->first();
    $anchor = AccessTestHelpers::folder($storage, null, 'anchor');
    AccessTestHelpers::folder($storage, $anchor, 'child');
    AccessTestHelpers::grant(
        $root,
        RoleRegistry::READER,
        scope: GrantScope::Item,
        subjectId: 1,
    );
    AccessTestHelpers::grant(
        $anchor,
        RoleRegistry::READER,
        scope: GrantScope::Subtree,
        subjectId: 1,
    );

    $user = new TestUser;
    $user->forceFill(['id' => 1]);
    $user->exists = true;

    app(NodeReadRepository::class)->candidateNodeIds($user);

    app(MetricsRecorder::class)->assertHistogram(name: 'vaulter_tree_cte_anchors', labels: ['tier' => 'S']);
});

it('records vaulter_activity_write_total when activity is persisted', function (): void {
    // Provisioning records drive.created through the EventRecorder.
    DriveTestHelpers::drive()->model();

    app(MetricsRecorder::class)->assertCounter(
        name: 'vaulter_activity_write_total',
        labels: ['verb' => 'drive.created'],
    );
});

it('records vaulter_broadcast_queue_wait_seconds for queued broadcast jobs', function (): void {
    $fake = app(MetricsRecorder::class);
    $listener = new RecordBroadcastQueueWait($fake);

    $payload = [
        'uuid' => 'job-1',
        'displayName' => BroadcastEvent::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'pushedAt' => microtime(true) - 0.05,
    ];

    $job = new SyncJob(app(), json_encode($payload, JSON_THROW_ON_ERROR), 'sync', 'default');
    $listener->handle(new JobProcessing('sync', $job));

    $fake->assertHistogram(name: 'vaulter_broadcast_queue_wait_seconds');
});

it('records vaulter_subtree_nodes_touched during move cycle guard', function (): void {
    $drive = DriveTestHelpers::drive();
    $parent = $drive->folders()->create('parent');
    $child = $drive->folders()->create('child', $parent);

    $context = DriveContext::for(
        drive: $drive->model(),
        actor: Actor::user(DriveTestHelpers::actor($drive, 1)),
        correlationId: 'subtree-metrics',
    );
    expect(fn () => app(MoveNodeAction::class)->execute($context, new MoveNodeInput(
        node: DriveTestHelpers::model($parent),
        into: DriveTestHelpers::model($child),
    )))->toThrow(NodeCycleException::class);

    app(MetricsRecorder::class)->assertHistogram(
        name: 'vaulter_subtree_nodes_touched',
        labels: ['operation' => 'subtree_mutate'],
    );
});

it('records vaulter_collab_room_active from hub stats', function (): void {
    Http::fake([
        'http://collab.test/stats' => Http::response(['rooms' => 2, 'connections' => 3]),
    ]);

    putenv('VAULTER_COLLAB_STATS_URL=http://collab.test/stats');

    $fake = app(MetricsRecorder::class);
    new CollaborationRoomMetrics($fake)->observeActiveRooms();

    $fake->assertGauge(name: 'vaulter_collab_room_active');
});
