<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Artisan;
use Vaulter\Doctor\Checks\QueueCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Doctor\DoctorRegistry;
use Vaulter\Doctor\DoctorRunner;
use Vaulter\Doctor\DoctorSeverity;
use Vaulter\Tests\Doctor\ClosureCheck;

function doctorContext(bool $production = false): DoctorContext
{
    return new DoctorContext(production: $production, probeStorage: false, sampleLimit: 1000, now: CarbonImmutable::now('UTC'));
}

/** @param list<ClosureCheck> $checks */
function doctorRegistry(array $checks): DoctorRegistry
{
    $container = new Container;
    foreach ($checks as $index => $check) {
        $container->instance('check.'.$index, $check);
    }
    $container->tag(array_map(static fn (int $index): string => 'check.'.$index, array_keys($checks)), DoctorRegistry::TAG);

    return new DoctorRegistry($container);
}

function okCheck(string $key): ClosureCheck
{
    return new ClosureCheck($key, static fn (): array => [DoctorFinding::ok(code: $key.'.ok', message: 'fine')]);
}

it('orders the checks by key', function (): void {
    $keys = array_map(static fn ($check): string => $check->key(), doctorRegistry([okCheck('z.last'), okCheck('a.first'), okCheck('m.middle')])->all());

    expect($keys)->toBe(['a.first', 'm.middle', 'z.last']);
});

it('refuses two checks with the same key', function (): void {
    doctorRegistry([okCheck('dup.key'), okCheck('dup.key')])->all();
})->throws(LogicException::class, 'dup.key');

it('refuses a tagged service that is not a check', function (): void {
    $container = new Container;
    $container->instance('not-a-check', new stdClass);
    $container->tag(['not-a-check'], DoctorRegistry::TAG);

    (new DoctorRegistry($container))->all();
})->throws(LogicException::class, 'must implement');

it('selects checks by whole key segments', function (): void {
    $registry = doctorRegistry([okCheck('core.schema.tables'), okCheck('core.schema.key_types'), okCheck('core.schemaX'), okCheck('drive.scan.policy')]);

    expect(array_map(static fn ($check): string => $check->key(), $registry->matching(['core.schema'])))->toBe(['core.schema.key_types', 'core.schema.tables'])
        ->and(array_map(static fn ($check): string => $check->key(), $registry->matching(['drive', 'core.schemaX'])))->toBe(['core.schemaX', 'drive.scan.policy'])
        ->and($registry->unmatched(['core.schema', 'nope', 'core.sch']))->toBe(['nope', 'core.sch']);
});

it('turns an exception into doctor.check_failed without leaking its message', function (): void {
    $runner = new DoctorRunner(doctorRegistry([
        new ClosureCheck('fake.throws', static fn (): array => throw new RuntimeException('password=hunter2')),
        okCheck('fake.ok'),
    ]));

    $report = $runner->run(doctorContext());
    $failed = $report->checks[1]->findings[0];

    expect($report->checks[1]->key)->toBe('fake.throws')
        ->and($failed->severity)->toBe(DoctorSeverity::Error)
        ->and($failed->code)->toBe('doctor.check_failed')
        ->and($failed->message)->toBe(RuntimeException::class)
        ->and($failed->hint)->toBe('run with -v')
        ->and(json_encode($report->toArray()))->not->toContain('hunter2')
        ->and($report->checks[0]->findings[0]->severity)->toBe(DoctorSeverity::Ok)
        ->and($report->exitCode())->toBe(1);
});

it('keeps the exception message out of the findings and the JSON document, also when verbose', function (): void {
    $runner = new DoctorRunner(doctorRegistry([new ClosureCheck('fake.throws', static fn (): array => throw new RuntimeException('dsn=pgsql://u:s3cret@db/app'))]));

    $quiet = $runner->run(doctorContext());
    $verbose = $runner->run(doctorContext(), exposeMessages: true);

    expect($quiet->checks[0]->detail)->toBeNull()
        ->and($verbose->checks[0]->detail)->toBe('dsn=pgsql://u:s3cret@db/app')
        ->and($verbose->checks[0]->findings[0]->message)->toBe(RuntimeException::class)
        ->and($verbose->checks[0]->findings[0]->hint)->toBe('run with -v')
        ->and(json_encode($verbose->toArray()))->not->toContain('s3cret');
});

it('prints the exception message of a failed check only in verbose human output', function (): void {
    $this->app->instance('fake.throws', new ClosureCheck('fake.throws', static fn (): array => throw new RuntimeException('boom-detail')));
    $this->app->tag(['fake.throws'], DoctorRegistry::TAG);

    $this->artisan('vaulter:doctor', ['--check' => ['fake.throws']])->doesntExpectOutputToContain('boom-detail')->assertExitCode(1);
    $this->artisan('vaulter:doctor', ['--check' => ['fake.throws'], '-v' => true])->expectsOutputToContain('detail: boom-detail')->assertExitCode(1);

    Artisan::call('vaulter:doctor', ['--check' => ['fake.throws'], '--json' => true, '-v' => true]);
    expect(Artisan::output())->not->toContain('boom-detail');
});

it('reports a check without findings as an implementation error', function (): void {
    $runner = new DoctorRunner(doctorRegistry([new ClosureCheck('fake.empty', static fn (): array => [])]));

    $finding = $runner->run(doctorContext())->checks[0]->findings[0];

    expect($finding->code)->toBe('doctor.no_findings')->and($finding->severity)->toBe(DoctorSeverity::Error);
});

it('trims evidence to 20 short entries', function (): void {
    $evidence = [];
    for ($i = 0; $i < 30; $i++) {
        $evidence['k'.$i] = $i;
    }
    $evidence['k0'] = str_repeat('x', 500);
    $evidence['k1'] = ['nested'];
    $runner = new DoctorRunner(doctorRegistry([new ClosureCheck('fake.evidence', static fn (): array => [DoctorFinding::ok(code: 'fake.ev', message: 'evidence', evidence: $evidence)])]));

    $trimmed = $runner->run(doctorContext())->checks[0]->findings[0]->evidence;

    expect($trimmed)->toHaveCount(20)
        ->and(mb_strlen($trimmed['k0']))->toBe(200)
        ->and($trimmed['k1'])->toBe('array');
});

it('does not pass the queue check when the default connection is missing', function (): void {
    $missing = new QueueCheck(new Repository([
        'queue' => [
            'default' => 'redis',
            'connections' => ['sync' => ['driver' => 'sync']],
        ],
    ]));
    $blankDriver = new QueueCheck(new Repository([
        'queue' => [
            'default' => 'redis',
            'connections' => ['redis' => ['connection' => 'default']],
        ],
    ]));
    $configured = new QueueCheck(new Repository([
        'queue' => [
            'default' => 'redis',
            'connections' => ['redis' => ['driver' => 'redis', 'connection' => 'default']],
        ],
    ]));

    $missingReport = (new DoctorRunner(doctorRegistry([$missing])))->run(doctorContext());
    $blankReport = (new DoctorRunner(doctorRegistry([$blankDriver])))->run(doctorContext());
    $configuredReport = (new DoctorRunner(doctorRegistry([$configured])))->run(doctorContext());

    expect($missingReport->checks[0]->findings[0]->code)->toBe('core.queue.missing')
        ->and($missingReport->checks[0]->findings[0]->severity)->toBe(DoctorSeverity::Error)
        ->and($missingReport->checks[0]->findings[0]->evidence)->toBe(['connection' => 'redis'])
        ->and($missingReport->summary())->toBe(['ok' => 0, 'warning' => 0, 'error' => 1])
        ->and($missingReport->exitCode())->toBe(1)
        ->and(json_encode($missingReport->toArray()))->toContain('core.queue.missing')
        ->and($blankReport->checks[0]->findings[0]->code)->toBe('core.queue.missing')
        ->and($blankReport->exitCode())->toBe(1)
        ->and($configuredReport->checks[0]->findings[0]->code)->toBe('core.queue.asynchronous')
        ->and($configuredReport->exitCode())->toBe(0);
});

it('counts findings and fails only on an error', function (): void {
    $warning = new ClosureCheck('fake.warning', static fn (): array => [DoctorFinding::warning(code: 'w', message: 'careful')]);
    $error = new ClosureCheck('fake.error', static fn (): array => [DoctorFinding::error(code: 'e', message: 'broken')]);

    $warned = (new DoctorRunner(doctorRegistry([okCheck('fake.ok'), $warning])))->run(doctorContext());
    $broken = (new DoctorRunner(doctorRegistry([okCheck('fake.ok'), $warning, $error])))->run(doctorContext());

    expect($warned->summary())->toBe(['ok' => 1, 'warning' => 1, 'error' => 0])
        ->and($warned->exitCode())->toBe(0)
        ->and($broken->summary())->toBe(['ok' => 1, 'warning' => 1, 'error' => 1])
        ->and($broken->exitCode())->toBe(1);
});

it('exits 0, 1 and 2 from the command', function (): void {
    $this->app->instance('fake.warning', new ClosureCheck('fake.warning', static fn (): array => [DoctorFinding::warning(code: 'w', message: 'careful')]));
    $this->app->instance('fake.error', new ClosureCheck('fake.error', static fn (): array => [DoctorFinding::error(code: 'e', message: 'broken')]));
    $this->app->tag(['fake.warning', 'fake.error'], DoctorRegistry::TAG);

    expect(Artisan::call('vaulter:doctor', ['--check' => ['fake.warning']]))->toBe(0)
        ->and(Artisan::call('vaulter:doctor', ['--check' => ['fake.error']]))->toBe(1)
        ->and(Artisan::call('vaulter:doctor', ['--check' => ['fake.nothing']]))->toBe(2)
        ->and(Artisan::call('vaulter:doctor', ['--check' => ['fake.warning'], '--sample' => '0']))->toBe(2);
});

it('prints the findings and their hints', function (): void {
    $this->app->instance('fake.warning', new ClosureCheck('fake.warning', static fn (): array => [DoctorFinding::warning(code: 'fake.warn', message: 'careful <now>', hint: 'be careful')]));
    $this->app->tag(['fake.warning'], DoctorRegistry::TAG);

    $this->artisan('vaulter:doctor', ['--check' => ['fake.warning']])
        ->expectsOutputToContain('careful <now>')
        ->expectsOutputToContain('hint: be careful')
        ->expectsOutputToContain('0 ok, 1 warning, 0 error')
        ->assertExitCode(0);
});

it('escalates a warning only under --production', function (): void {
    expect(doctorContext()->strictSeverity())->toBe(DoctorSeverity::Warning)
        ->and(doctorContext(production: true)->strictSeverity())->toBe(DoctorSeverity::Error)
        ->and(doctorContext(production: true)->mayProbeStorage())->toBeTrue()
        ->and(doctorContext()->mayProbeStorage())->toBeFalse();
});
