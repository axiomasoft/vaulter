<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\CoreModule;
use Vaulter\Kernel;
use Vaulter\Tests\Concerns\WithoutDatabaseSchema;
use Vaulter\Tests\TestCase;
use Vaulter\Webhooks\WebhooksModule;

uses(TestCase::class, WithoutDatabaseSchema::class);

/** @return array<string, array<string, mixed>> the modules of `vaulter:modules --json`, by id */
function modulesJson(): array
{
    expect(Artisan::call('vaulter:modules', ['--json' => true]))->toBe(0);
    $document = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($document['valid'])->toBeTrue()
        ->and($document['fingerprint'])->toBe(app(Kernel::class)->fingerprint())
        ->and($document['reasons'])->toBe([]);

    return array_column($document['modules'], null, 'id');
}

it('lists every offered module with its policy, activation and reason', function (): void {
    $modules = modulesJson();

    expect($modules['core'])->toMatchArray(['manifest' => CoreModule::class, 'protocol' => '1.0', 'policy' => 'auto', 'active' => true, 'reason' => 'default'])
        ->and($modules['webhooks'])->toMatchArray(['manifest' => WebhooksModule::class, 'protocol' => '1.0', 'policy' => 'opt-in', 'active' => false, 'reason' => 'default'])
        ->and($modules['core'])->toHaveKey('package');
});

it('shows the schema migrations the database has not run', function (): void {
    $modules = modulesJson();

    // No schema in this suite: every create-migration of the core is pending; webhooks ships none of its own.
    expect($modules['core']['schema_pending'])->toHaveCount(count(glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: []))
        ->and($modules['webhooks']['schema_pending'])->toBe([]);
});

it('shows the slots a module defines and the contributions it makes', function (): void {
    $slots = array_column(modulesJson()['core']['slots'], null, 'slot');

    expect($slots[DoctorCheck::class]['role'])->toBe('defines')
        ->and($slots[DoctorCheck::class]['contributions'])->toBe(10);
});

it('prints a table with the fingerprint', function (): void {
    // expectsOutputToContain consumes one written line per expectation: assert the table as a whole.
    $exit = Artisan::call('vaulter:modules');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toMatch('/webhooks\s*\|[^\n]*opt-in[^\n]*no \(default\)/')
        ->and($output)->toContain('fingerprint '.app(Kernel::class)->fingerprint());
});
