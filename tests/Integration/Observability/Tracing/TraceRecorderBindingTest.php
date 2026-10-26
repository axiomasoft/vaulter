<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Observability\NullTraceRecorder;
use Vaulter\Testing\FakeTraceRecorder;

beforeEach(function (): void {
    config()->set('vaulter.observability.tracing.enabled', false);
    config()->set('vaulter.observability.tracing.recorder', null);
    $this->app->forgetInstance(TraceRecorder::class);
});

it('resolves NullTraceRecorder by default', function (): void {
    expect(app(TraceRecorder::class))->toBeInstanceOf(NullTraceRecorder::class);
});

it('resolves a valid host adapter when tracing is enabled', function (): void {
    config()->set('vaulter.observability.tracing.enabled', true);
    config()->set('vaulter.observability.tracing.recorder', FakeTraceRecorder::class);

    expect(app(TraceRecorder::class))->toBeInstanceOf(FakeTraceRecorder::class);
});

it('falls back to Null for an invalid tracing recorder when enabled', function (): void {
    Log::spy();

    config()->set('vaulter.observability.tracing.enabled', true);
    config()->set('vaulter.observability.tracing.recorder', 'Definitely\\Missing\\Recorder');

    expect(app(TraceRecorder::class))->toBeInstanceOf(NullTraceRecorder::class);
    Log::shouldHaveReceived('warning');
});

it('NullTraceRecorder executes span callbacks without recording spans', function (): void {
    $fake = new FakeTraceRecorder;
    $null = new NullTraceRecorder;

    expect($null->span(name: 'vaulter.action.upload.sweep', operation: fn (): int => 7))->toBe(7);

    expect($fake->spans())->toBe([]);
});

it('rejects OpenTelemetry types, unselected span names and forbidden attributes', function (): void {
    $roots = [
        __DIR__.'/../../../../packages/core/src',
        __DIR__.'/../../../../packages/drive/src',
        __DIR__.'/../../../../packages/media/src',
        __DIR__.'/../../../../packages/documents/src',
    ];
    $forbidden = [
        'OpenTelemetry',
        'open-telemetry',
        'startSpan',
        'endSpan',
        'vaulter.repository.tree.read',
        'vaulter.action.tree.move',
        'vaulter.action.upload.tus',
        'vaulter.action.upload.s3',
        'vaulter.controller.download',
        'vaulter.repository.cas.commit',
        'vaulter.action.cas.commit',
        'vaulter.action.cas.gc',
        'vaulter.action.collaboration.session',
        'vaulter.action.collaboration.snapshot',
    ];
    $hits = [];

    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            expect($contents)->not->toBeFalse();

            foreach ($forbidden as $needle) {
                if (str_contains($contents, $needle)) {
                    $hits[] = $file->getPathname().': '.$needle;
                }
            }
        }
    }

    expect($hits)->toBe([]);
});

it('FakeTraceRecorder records parent child nesting and error status', function (): void {
    $fake = new FakeTraceRecorder;

    try {
        $fake->span(
            name: 'parent',
            operation: fn (): mixed => $fake->span(
                name: 'child',
                operation: function () use ($fake): void {
                    $fake->span(name: 'boom', operation: function (): void {
                        throw new RuntimeException('fail');
                    });
                },
            ),
        );
    } catch (RuntimeException) {
    }

    expect($fake->spans())->toHaveCount(3);
    $fake->assertSpan(name: 'child', parent: 'parent');
    $fake->assertSpan(
        name: 'boom',
        attributes: [
            'vaulter.outcome' => 'internal_error',
        ],
        parent: 'child',
    );
    expect($fake->spans()[2]['status'])->toBe('error');
});
