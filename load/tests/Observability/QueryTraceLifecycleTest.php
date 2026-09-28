<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Vaulter\Load\Http\Middleware\BindQueryTraceContext;
use Vaulter\Load\Observability\QueryTraceRecorder;

beforeEach(function (): void {
    $this->tracePath = sys_get_temp_dir().'/vaulter-r04-lifecycle-'.bin2hex(random_bytes(8)).'.jsonl';
    $this->traceEnvironment = [];

    foreach (['LOAD_RUN_ID' => 'r04-lifecycle', 'LOAD_DB_TRACE_PATH' => $this->tracePath, 'LOAD_DB_TRACE' => '1'] as $key => $value) {
        $this->traceEnvironment[$key] = [getenv($key), $_ENV[$key] ?? null];
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
    }

    $this->recorder = new QueryTraceRecorder;
    $this->recorder->register();
});

afterEach(function (): void {
    @unlink($this->tracePath);

    foreach ($this->traceEnvironment as $key => [$value, $env]) {
        putenv($value === false ? $key : $key.'='.$value);

        if ($env === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $env;
        }
    }
});

function r04TraceRows(string $path): array
{
    return array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES));
}

it('flushes HTTP SQL only on middleware termination and does not duplicate it', function (): void {
    $middleware = new BindQueryTraceContext($this->recorder);
    $request = request()->create('/api/vaulter/storages/1/nodes', 'GET');
    $response = $middleware->handle($request, function () {
        DB::connection('testing')->select('select ? as probe', ['private-binding']);

        return response('ok');
    });

    expect($response->getStatusCode())->toBe(200)
        ->and(is_file($this->tracePath))->toBeFalse();

    $middleware->terminate();
    $middleware->terminate();
    $rows = r04TraceRows($this->tracePath);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['command_id'])->toBe('http:tree_list')
        ->and(file_get_contents($this->tracePath))->not->toContain('private-binding');
});

it('flushes failed and successful Artisan invocations with independent identities', function (): void {
    $input = new ArrayInput([]);
    $output = new NullOutput;

    foreach ([1, 0] as $exitCode) {
        Event::dispatch(new CommandStarting('vaulter:sweep-uploads', $input, $output));
        DB::connection('testing')->select('select 1');
        Event::dispatch(new CommandFinished('vaulter:sweep-uploads', $input, $output, $exitCode));
    }

    $rows = r04TraceRows($this->tracePath);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['command_id'])->toBe('artisan:vaulter:sweep-uploads')
        ->and($rows[0]['invocation_id'])->not->toBe($rows[1]['invocation_id'])
        ->and(array_column($rows, 'ordinal'))->toBe([1, 1]);
});

it('flushes a failed job once and gives the next processed job a new invocation', function (): void {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn([]);
    $job->shouldReceive('resolveName')->andReturn('R04FakeJob');
    $exception = new RuntimeException('fake job failure');
    Event::dispatch(new JobProcessing('redis', $job));
    DB::connection('testing')->select('select 1');
    Event::dispatch(new JobExceptionOccurred('redis', $job, $exception));
    Event::dispatch(new JobFailed('redis', $job, $exception));
    Event::dispatch(new JobProcessing('redis', $job));
    DB::connection('testing')->select('select 2');
    Event::dispatch(new JobProcessed('redis', $job));

    $rows = r04TraceRows($this->tracePath);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['command_id'])->toBe('job:'.$job::class)
        ->and($rows[0]['invocation_id'])->not->toBe($rows[1]['invocation_id'])
        ->and(array_column($rows, 'ordinal'))->toBe([1, 1]);
});

it('records actual transaction state and groups SQL independently of bound values', function (): void {
    $this->recorder->bindHttpRequest(request()->create('/api/vaulter/storages/1/nodes', 'GET'));
    $connection = DB::connection('testing');
    $connection->select('select ? as probe', ['first-private-value']);
    $connection->beginTransaction();
    $connection->select('select ? as probe', ['second-private-value']);
    $connection->rollBack();
    $connection->select('select ? as probe', ['third-private-value']);
    $this->recorder->flush();
    $rows = r04TraceRows($this->tracePath);

    expect(array_column($rows, 'in_transaction'))->toBe([false, true, false])
        ->and(array_unique(array_column($rows, 'sql_template_hash')))->toHaveCount(1)
        ->and(array_column($rows, 'ordinal'))->toBe([1, 2, 3]);
});
