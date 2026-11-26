<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\TestCase;
use Vaulter\Testing\Concurrency\ForkRace;
use Vaulter\Testing\Concurrency\RaceOutcome;
use Vaulter\Testing\Tenancy\MutableTenantResolver;

uses(TestCase::class, DatabaseMigrations::class);

afterEach(fn () => MutableTenantResolver::clearFixtureStamps());

it('a real PG owner profile race has one tenant winner and a hidden loser', function (): void {
    ForkRace::skipUnlessSupported($this);
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    $owner = DriveTestHelpers::host(7788);
    $tasks = [];
    foreach (['A', 'B'] as $tenant) {
        $tasks[] = function () use ($owner, $tenant): array {
            $resolver = new MutableTenantResolver;
            $resolver->tenant = $tenant;
            app()->instance(TenantResolver::class, $resolver);
            $pid = app(VaulterDatabase::class)->connection()->selectOne('select pg_backend_pid() as pid')->pid;
            try {
                $drive = Vaulter::drive($owner)->model();

                return ['pid' => $pid, 'tenant' => $tenant, 'winner' => true, 'drive' => $drive->id];
            } catch (NodeNotFoundException) {
                return ['pid' => $pid, 'tenant' => $tenant, 'winner' => false];
            }
        };
    }
    $outcomes = ForkRace::run($tasks);
    expect($outcomes[0]->ok)->toBeTrue()->and($outcomes[1]->ok)->toBeTrue()
        ->and($outcomes[0]->value['pid'])->not->toBe($outcomes[1]->value['pid']);
    $values = array_map(fn (RaceOutcome $outcome): array => $outcome->value, $outcomes);
    expect(array_filter($values, fn (array $value): bool => $value['winner']))->toHaveCount(1)
        ->and(Drive::query()->where('owner_id', 7788)->count())->toBe(1);
    $winner = array_values(array_filter($values, fn (array $value): bool => $value['winner']))[0];
    $drive = Drive::query()->where('owner_id', 7788)->firstOrFail();
    expect($drive->tenant_id)->toBe($winner['tenant'])->and($drive->rootNode()->firstOrFail()->tenant_id)->toBe($winner['tenant']);
    fwrite(STDOUT, 'TENANT_OWNER_RACE '.json_encode(['barrier' => 'ForkRace ready/release', 'connection' => $drive->getConnection()->getName(), 'database' => $drive->getConnection()->getDatabaseName(), 'outcomes' => $values]).PHP_EOL);
});
