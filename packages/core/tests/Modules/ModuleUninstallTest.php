<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\ValueObjects\Cas\BlobScope;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Testing\Concerns\FreshApplication;
use Vaulter\Tests\TestCase;
use Vaulter\VaulterServiceProvider;

uses(TestCase::class, DatabaseMigrations::class);

// D36 (P4.17): vaulter:module:uninstall executes. --force rolls back the migrations of the module's own schema paths
// only, newest first; --purge (with --force) deletes its rows and releases its own media pins (D23) in one transaction
// first. Refused, with nothing changed: an installed module that requires it, a foreign key of another owner's table,
// pins of another owner on its data (the media), references it cannot read, and — without --purge — rows or pins of
// its own. The suite application is core+media+drive+webhooks; the sets without webhooks or with documents are fresh
// applications on their own database.

/** @return list<string> the tables of the connection, sorted */
function uninstallTables(): array
{
    $tables = array_map(static fn (array $table): string => (string) $table['name'], Schema::getTables());
    sort($tables);

    return $tables;
}

/** @return list<string> the migrations recorded as run */
function uninstallRan(): array
{
    return DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
}

/** Pins a new committed blob for `$ownerType`; returns the blob. */
function uninstallPinnedBlob(string $bytes, string ...$ownerTypes): Blob
{
    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, $bytes);
    rewind($stream);
    $blob = app(BlobStore::class)->commit($stream, BlobScope::tenant('1'));
    app(VaulterDatabase::class)->transaction(static function () use ($blob, $ownerTypes): void {
        foreach ($ownerTypes as $i => $ownerType) {
            app(BlobPins::class)->pin($blob, $ownerType, 'ref-'.$i);
        }
    });

    return $blob;
}

/** @return array<string, int> */
function uninstallPins(): array
{
    return app(VaulterDatabase::class)->transaction(static fn (): array => app(BlobPins::class)->countByOwner());
}

function uninstallDriveRow(): void
{
    DB::table('v_drives')->insert(['owner_id' => 1, 'owner_type' => 'user', 'name' => 'Docs', 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * A fresh application with only the given Vaulter providers, migrated on its own database.
 *
 * @param list<class-string>   $providers
 * @param array<string, mixed> $config
 */
function uninstallApp(array $providers, array $config = []): Application
{
    $case = new class('uninstallSet') extends TestCase
    {
        /** @var list<class-string> */
        public static array $providers = [];

        protected function getPackageProviders($app): array
        {
            return self::$providers;
        }
    };
    $case::$providers = $providers;
    $app = FreshApplication::boot($config, case: $case);
    uninstallIn($app, static function () use ($app): void {
        $console = $app->make(ConsoleKernel::class);
        expect($console->call('migrate', ['--force' => true]))->toBe(0, $console->output());
    });

    return $app;
}

/**
 * Runs `$callback` with `$app` as the container and facade root.
 *
 * @template T
 *
 * @param  Closure(): T $callback
 * @return T
 */
function uninstallIn(Application $app, Closure $callback): mixed
{
    $current = Container::getInstance();
    Container::setInstance($app);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($app);

    try {
        return $callback();
    } finally {
        Container::setInstance($current);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($current instanceof Application ? $current : null);
    }
}

/** @return array{exit: int, output: string} */
function uninstallRun(Application $app, array $arguments): array
{
    return uninstallIn($app, static function () use ($app, $arguments): array {
        $console = $app->make(ConsoleKernel::class);
        $exit = $console->call('vaulter:module:uninstall', $arguments);

        return ['exit' => $exit, 'output' => $console->output()];
    });
}

const UNINSTALL_DRIVE_SET = [VaulterServiceProvider::class, VaulterMediaServiceProvider::class, VaulterDriveServiceProvider::class];

const UNINSTALL_DOCUMENTS_SET = [...UNINSTALL_DRIVE_SET, VaulterDocumentsServiceProvider::class];

beforeEach(function (): void {
    Storage::fake('local');
});

it('rolls back only the webhooks migrations on --force: its tables go, the drive and the media stay, migrate installs it again', function (): void {
    $this->artisan('vaulter:module:uninstall', ['module' => 'webhooks', '--force' => true])
        ->expectsOutputToContain('0001_01_01_000402_create_v_webhook_deliveries_table')
        ->expectsOutputToContain('The schema of "webhooks" is removed.')
        ->assertExitCode(0);

    expect(Schema::hasTable('v_webhook_endpoints'))->toBeFalse()
        ->and(Schema::hasTable('v_webhook_deliveries'))->toBeFalse()
        ->and(Schema::hasTable('v_drives'))->toBeTrue()
        ->and(Schema::hasTable('v_blobs'))->toBeTrue()
        ->and(uninstallRan())->not->toContain('0001_01_01_000401_create_v_webhook_endpoints_table')
        ->and(uninstallRan())->toContain('0001_01_01_000201_create_v_drives_table', '0001_01_01_000001_create_v_outbox_table');

    $this->artisan('vaulter:module:uninstall', ['module' => 'webhooks', '--force' => true])
        ->expectsOutputToContain('Nothing to roll back')
        ->assertExitCode(0);

    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    expect(Schema::hasTable('v_webhook_endpoints'))->toBeTrue();
});

it('refuses the drive while an installed module requires it, and changes nothing', function (string $option): void {
    $before = uninstallTables();

    $this->artisan('vaulter:module:uninstall', ['module' => 'drive', '--force' => true, $option => true])
        ->expectsOutputToContain('module "webhooks" requires it')
        ->expectsOutputToContain('nothing was changed')
        ->assertExitCode(1);

    expect(uninstallTables())->toBe($before);
})->with(['--force', '--purge']);

it('refuses a foreign key of a table it does not own, and changes nothing', function (): void {
    Schema::create('host_hook_audits', static function (Blueprint $table): void {
        $table->id();
        $table->foreignId('endpoint_id')->constrained('v_webhook_endpoints');
    });
    $before = uninstallTables();

    try {
        $this->artisan('vaulter:module:uninstall', ['module' => 'webhooks', '--force' => true, '--purge' => true])
            ->expectsOutputToContain('host_hook_audits references v_webhook_endpoints')
            ->assertExitCode(1);

        expect(uninstallTables())->toBe($before);
    } finally {
        Schema::drop('host_hook_audits');
    }
});

it('refuses the media while the drive requires it and pins its blobs; the pins stay', function (): void {
    uninstallPinnedBlob('kept', 'drive.revision_blob');

    $this->artisan('vaulter:module:uninstall', ['module' => 'media', '--force' => true, '--purge' => true])
        ->expectsOutputToContain('module "drive" requires it')
        ->expectsOutputToContain('reference(s) of "drive.revision_blob" are kept on its data')
        ->assertExitCode(1);

    expect(uninstallPins())->toBe(['drive.revision_blob' => 1])
        ->and(Schema::hasTable('v_blob_references'))->toBeTrue();
});

it('changes nothing on a dry run and shows the rows and references it would touch', function (): void {
    uninstallPinnedBlob('planned', 'drive.revision_blob', 'drive.revision_blob');
    uninstallDriveRow();
    $before = uninstallTables();
    $ran = uninstallRan();

    $this->artisan('vaulter:module:uninstall', ['module' => 'drive'])
        ->expectsOutputToContain('media: drive.revision_blob (2)')
        ->expectsOutputToContain('Dry run: nothing was changed.')
        ->assertExitCode(1);

    expect(uninstallTables())->toBe($before)
        ->and(uninstallRan())->toBe($ran)
        ->and(uninstallPins())->toBe(['drive.revision_blob' => 2])
        ->and(DB::table('v_drives')->count())->toBe(1);
});

it('exits 2 on a usage error', function (array $arguments, string $message): void {
    $this->artisan('vaulter:module:uninstall', $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(2);
})->with([
    'purge without force' => [['module' => 'webhooks', '--purge' => true], '--purge deletes rows only together with --force'],
    'unknown module' => [['module' => 'nope', '--force' => true], 'No installed module is called "nope"'],
]);

it('purges the drive in core+media+drive: its rows and its own pins go, other pins and the blobs stay', function (): void {
    $app = uninstallApp(UNINSTALL_DRIVE_SET);
    $blob = uninstallIn($app, static function (): Blob {
        uninstallDriveRow();

        return uninstallPinnedBlob('shared', 'drive.revision_blob', 'drive.revision_blob', 'acme.attachment');
    });

    $refused = uninstallRun($app, ['module' => 'drive', '--force' => true]);
    expect($refused['exit'])->toBe(1)
        ->and($refused['output'])->toContain('its tables hold rows; --purge deletes them')
        ->toContain('it keeps references; --purge releases them');
    uninstallIn($app, static fn () => expect(DB::table('v_drives')->count())->toBe(1)
        ->and(uninstallPins())->toBe(['acme.attachment' => 1, 'drive.revision_blob' => 2]));

    $purged = uninstallRun($app, ['module' => 'drive', '--force' => true, '--purge' => true]);
    expect($purged['exit'])->toBe(0, $purged['output'])
        ->and($purged['output'])->toContain('1 row(s), 2 reference(s)');

    uninstallIn($app, static function () use ($blob): void {
        expect(uninstallPins())->toBe(['acme.attachment' => 1])
            ->and(Blob::query()->whereKey($blob->getKey())->exists())->toBeTrue()
            ->and(Schema::hasTable('v_drives'))->toBeFalse()
            ->and(Schema::hasTable('v_file_revision_blobs'))->toBeFalse()
            ->and(Schema::hasTable('v_blobs'))->toBeTrue()
            ->and(uninstallRan())->each->not->toStartWith('0001_01_01_0002');
    });
});

it('refuses the drive while documents is installed', function (): void {
    $app = uninstallApp(UNINSTALL_DOCUMENTS_SET);

    $run = uninstallRun($app, ['module' => 'drive', '--force' => true]);

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain('module "documents" requires it');
    uninstallIn($app, static fn () => expect(Schema::hasTable('v_drives'))->toBeTrue()->and(Schema::hasTable('v_documents'))->toBeTrue());
});

it('rolls back documents on --force: its tables go, the drive stays', function (): void {
    $app = uninstallApp(UNINSTALL_DOCUMENTS_SET);

    $run = uninstallRun($app, ['module' => 'documents', '--force' => true]);

    expect($run['exit'])->toBe(0, $run['output']);
    uninstallIn($app, static fn () => expect(Schema::hasTable('v_documents'))->toBeFalse()
        ->and(Schema::hasTable('v_document_revisions'))->toBeFalse()
        ->and(Schema::hasTable('v_node_comments'))->toBeFalse()
        ->and(Schema::hasTable('v_drives'))->toBeTrue()
        ->and(Schema::hasTable('v_nodes'))->toBeTrue());
});

it('refuses to execute when the pins cannot be read: the media is switched off', function (): void {
    $app = uninstallApp(UNINSTALL_DRIVE_SET, ['vaulter.modules.drive' => false, 'vaulter.modules.media' => false]);

    $run = uninstallRun($app, ['module' => 'drive', '--force' => true, '--purge' => true]);

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain('the references in module "media" cannot be read');
    uninstallIn($app, static fn () => expect(Schema::hasTable('v_drives'))->toBeTrue());
});
