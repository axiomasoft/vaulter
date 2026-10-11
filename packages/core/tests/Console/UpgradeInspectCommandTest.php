<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Schema\UpgradePreflight;

function dropV03Drives(): void
{
    Schema::dropIfExists(app(DatabaseConfig::class)->table('drives'));
}

function createLegacyStorages(): void
{
    Schema::create(app(DatabaseConfig::class)->legacyTable('storages'), static function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->softDeletes();
    });
}

it('has no problems on a schema without legacy tables', function (): void {
    expect(app(UpgradePreflight::class)->problems(app(VaulterDatabase::class)->connection()))->toBe([]);
});

it('reports a legacy storages table that was never migrated to drives', function (): void {
    Schema::disableForeignKeyConstraints();
    dropV03Drives();
    createLegacyStorages();
    app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->legacyTable('storages'))->insert(['name' => 'old', 'deleted_at' => now()]);

    $problems = app(UpgradePreflight::class)->problems(app(VaulterDatabase::class)->connection());

    expect($problems)->not->toBe([])->and($problems[0])->toContain('soft-deleted rows');
});

it('prints ready and exits 0 when nothing blocks the upgrade', function (): void {
    $this->artisan('vaulter:upgrade:inspect')->expectsOutputToContain('ready')->assertExitCode(0);
});

it('prints the JSON report', function (): void {
    $this->artisan('vaulter:upgrade:inspect', ['--json' => true])
        ->expectsOutput('{"schema":"vaulter.upgrade-inspect/v1","ready":true,"problems":[]}')
        ->assertExitCode(0);
});

it('lists the problems and exits 1', function (): void {
    Schema::disableForeignKeyConstraints();
    dropV03Drives();
    createLegacyStorages();
    app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->legacyTable('storages'))->insert(['name' => 'old', 'deleted_at' => now()]);

    $this->artisan('vaulter:upgrade:inspect')->expectsOutputToContain('soft-deleted rows')->assertExitCode(1);
});
