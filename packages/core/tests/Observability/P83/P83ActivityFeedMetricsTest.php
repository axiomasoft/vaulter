<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Http\Controllers\Activity\ActivityController;
use Vaulter\Support\LoadTier;
use Vaulter\Testing\FakeMetricsRecorder;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    config()->set('vaulter.observability.enabled', true);
    config()->set('vaulter.observability.recorder', FakeMetricsRecorder::class);
    config()->set('vaulter.features.activity', true);
    $this->app->forgetInstance(MetricsRecorder::class);
});

it('records vaulter_activity_feed_duration_seconds for storage feed reads', function (): void {
    LoadTier::set('M');

    config()->set('vaulter.features.grants', true);
    config()->set('vaulter.features.activity', true);

    $drive = DriveTestHelpers::drive();
    $storage = $drive->model();
    AccessTestHelpers::grant($drive->root(), RoleRegistry::READER, subjectId: 1);

    $user = new TestUser;
    $user->forceFill(['id' => 1]);
    $user->exists = true;

    $request = Request::create('/api/vaulter/storages/'.$storage->getKey().'/activity', 'GET');
    $request->setUserResolver(static fn (): TestUser => $user);

    app(ActivityController::class)->forStorage($request, $storage);

    app(MetricsRecorder::class)->assertHistogram(
        name: 'vaulter_activity_feed_duration_seconds',
        labels: ['filtered' => 'false', 'tier' => 'M'],
    );
});
