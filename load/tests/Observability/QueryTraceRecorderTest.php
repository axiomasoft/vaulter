<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vaulter\Load\Observability\QueryTraceRecorder;

it('records normalized query metadata without bindings', function (): void {
    $recorder = new QueryTraceRecorder;
    $recorder->register();
    $recorder->bindHttpRequest(request()->create('/api/vaulter/storages/1/nodes', 'GET'));

    putenv('LOAD_RUN_ID=test-run-trace');
    $_ENV['LOAD_RUN_ID'] = 'test-run-trace';

    DB::connection('testing')->select('select 1 as probe');

    $rows = $recorder->records();

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toHaveKeys([
            'run_id',
            'command_id',
            'invocation_id',
            'connection',
            'sql_template_hash',
            'duration_ms',
            'ordinal',
            'in_transaction',
        ])
        ->and($rows[0]['run_id'])->toBe('test-run-trace')
        ->and($rows[0]['command_id'])->toBe('http:tree_list')
        ->and($rows[0])->not->toHaveKey('bindings')
        ->and($rows[0])->not->toHaveKey('sql')
        ->and($rows[0]['sql_template_hash'])->toMatch('/^[a-f0-9]{64}$/');
});

it('appends separate invocations and flushes each only once', function (): void {
    $path = sys_get_temp_dir().'/vaulter-p3-trace-'.bin2hex(random_bytes(8)).'.jsonl';
    putenv('LOAD_RUN_ID=test-run-trace');
    $_ENV['LOAD_RUN_ID'] = 'test-run-trace';
    putenv('LOAD_DB_TRACE_PATH='.$path);
    $_ENV['LOAD_DB_TRACE_PATH'] = $path;

    try {
        $recorder = new QueryTraceRecorder;
        $recorder->register();
        $recorder->bindHttpRequest(request()->create('/api/vaulter/storages/1/nodes', 'GET'));
        DB::connection('testing')->select('select 1 as probe');
        $recorder->flush();
        $recorder->flush();

        $recorder->bindHttpRequest(request()->create('/api/vaulter/storages/1/nodes', 'GET'));
        DB::connection('testing')->select('select 2 as probe');
        $recorder->flush();

        $rows = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES));

        expect($rows)->toHaveCount(2)
            ->and($rows[0]['invocation_id'])->not->toBe($rows[1]['invocation_id'])
            ->and($rows[0]['ordinal'])->toBe(1)
            ->and($rows[1]['ordinal'])->toBe(1);
    } finally {
        @unlink($path);
        putenv('LOAD_DB_TRACE_PATH');
        unset($_ENV['LOAD_DB_TRACE_PATH']);
    }
});

it('does not record when LOAD_RUN_ID is empty', function (): void {
    $recorder = new QueryTraceRecorder;
    $recorder->register();

    putenv('LOAD_RUN_ID');
    unset($_ENV['LOAD_RUN_ID']);

    DB::connection('testing')->select('select 2 as probe');

    expect($recorder->records())->toBe([]);
});
