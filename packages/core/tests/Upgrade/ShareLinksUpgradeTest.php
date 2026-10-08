<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Tests\Access\AccessTestHelpers;

/**
 * 2026_10_07_000006_share_links_v1 turns a link's ability mask and require_login flag
 * into a role and an audience (PLAN2.D47, P4-I21): only a mask that is exactly the
 * abilities of a role a link may carry converts; any other stops the migration with the
 * list of links and changes nothing. The previous shape is rebuilt with the migration's
 * own down() over a migrated database, so the test runs on every driver.
 */
afterEach(function (): void {
    $db = app(VaulterDatabase::class)->connection();
    if ($db->transactionLevel() === 0 && ($db->getDriverName() === 'sqlite' || str_ends_with($db->getDatabaseName(), '_test'))) {
        $db->getSchemaBuilder()->dropAllTables();
    }
    RefreshDatabaseState::$migrated = false;
});

const SHARE_LINKS_MIGRATION = '2026_10_07_000006_share_links_v1';

function shareLinksMigration(): object
{
    return require __DIR__.'/../../database/migrations/2026_10_07_000006_share_links_v1.php';
}

/** Back to the shape between the access and the share-link migrations: a mask, a level, require_login. */
function shareLinksPrepare(object $test): Connection
{
    $test->artisan('migrate', ['--force' => true])->assertSuccessful();
    $db = app(VaulterDatabase::class)->connection();
    $db->table('migrations')->where('migration', SHARE_LINKS_MIGRATION)->update(['batch' => 99]);
    shareLinksMigration()->down();
    $db->table('migrations')->where('migration', SHARE_LINKS_MIGRATION)->delete();

    return $db;
}

function shareLinksNode(): int|string
{
    return AccessTestHelpers::folder(AccessTestHelpers::storage(), null, 'shared')->getKey();
}

/** @param array<string, mixed> $extra */
function shareLinksRow(Connection $db, int|string $nodeId, string $token, int $mask, int $version = 1, bool $login = false, array $extra = []): int|string
{
    return $db->table(app(DatabaseConfig::class)->table('node_shares'))->insertGetId([
        'node_id' => $nodeId, 'token_hash' => hash('sha256', $token), 'token_prefix' => substr($token, 0, 8),
        'permissions' => $mask, 'permissions_version' => $version, 'level' => 'reader', 'require_login' => $login,
        'created_at' => now(), 'updated_at' => now(), ...$extra,
    ]);
}

it('converts masks and require_login into a role and an audience', function (): void {
    $db = shareLinksPrepare($this);
    $nodeId = shareLinksNode();
    $shares = app(DatabaseConfig::class)->table('node_shares');
    $ids = [
        'previewer' => shareLinksRow($db, $nodeId, 'previewer-link', 1),
        'reader' => shareLinksRow($db, $nodeId, 'reader-link', 65),
        'commenter' => shareLinksRow($db, $nodeId, 'commenter-link', 97),
        'legacy-reader' => shareLinksRow($db, $nodeId, 'legacy-reader', 1, 0),
        'legacy-commenter' => shareLinksRow($db, $nodeId, 'legacy-commenter', 33, 0),
        'login' => shareLinksRow($db, $nodeId, 'login-link', 65, 1, true),
    ];

    shareLinksMigration()->up();

    $row = static fn (string $key): object => $db->table($shares)->where('id', $ids[$key])->first(['role_key', 'role_version', 'audience', 'token_generation']);
    expect($row('previewer')->role_key)->toBe('previewer')
        ->and($row('reader')->role_key)->toBe('reader')
        ->and($row('commenter')->role_key)->toBe('commenter')
        ->and($row('legacy-reader')->role_key)->toBe('reader')
        ->and($row('legacy-commenter')->role_key)->toBe('commenter')
        ->and($row('login')->role_key)->toBe('reader')
        ->and($row('login')->audience)->toBe('authenticated')
        ->and($row('reader')->audience)->toBe('anyone')
        ->and((int) $row('reader')->role_version)->toBe(1)
        ->and((int) $row('reader')->token_generation)->toBe(1);
    foreach (['permissions', 'permissions_version', 'level', 'require_login', 'allow_discovery'] as $legacy) {
        expect($db->getSchemaBuilder()->hasColumn($shares, $legacy))->toBeFalse();
    }
    // The token is untouched.
    expect($db->table($shares)->where('id', $ids['reader'])->value('token_hash'))->toBe(hash('sha256', 'reader-link'));

    // A second run changes nothing.
    $before = $db->table($shares)->orderBy('id')->get()->all();
    shareLinksMigration()->up();
    expect($db->table($shares)->orderBy('id')->get()->all())->toEqual($before);
});

it('refuses masks that are not exactly a role and leaves the table as it was', function (): void {
    $db = shareLinksPrepare($this);
    $nodeId = shareLinksNode();
    $shares = app(DatabaseConfig::class)->table('node_shares');
    $good = shareLinksRow($db, $nodeId, 'good-link', 65);
    $zero = shareLinksRow($db, $nodeId, 'zero-link', 0);
    $download = shareLinksRow($db, $nodeId, 'download-link', 64);
    $writer = shareLinksRow($db, $nodeId, 'writer-link', 2023);
    $legacy = shareLinksRow($db, $nodeId, 'legacy-link', 3, 0);

    expect(fn () => shareLinksMigration()->up())->toThrow(UpgradePreflightException::class, 'share '.$zero);
    try {
        shareLinksMigration()->up();
    } catch (UpgradePreflightException $exception) {
        foreach ([$zero, $download, $writer, $legacy] as $id) {
            expect($exception->getMessage())->toContain('share '.$id.' ');
        }
        expect($exception->getMessage())->not->toContain('share '.$good.' ');
    }

    $schema = $db->getSchemaBuilder();
    expect($schema->hasColumn($shares, 'permissions'))->toBeTrue()
        ->and($schema->hasColumn($shares, 'role_key'))->toBeFalse()
        ->and((int) $db->table($shares)->where('id', $good)->value('permissions'))->toBe(65);
});

it('finishes a run that stopped after adding the columns', function (): void {
    $db = shareLinksPrepare($this);
    $nodeId = shareLinksNode();
    $shares = app(DatabaseConfig::class)->table('node_shares');
    $converted = shareLinksRow($db, $nodeId, 'converted-link', 65);
    $pending = shareLinksRow($db, $nodeId, 'pending-link', 97, 1, true);
    $grammar = $db->getQueryGrammar();
    $db->statement('ALTER TABLE '.$grammar->wrapTable($shares).' ADD COLUMN '.$grammar->wrap('role_key')." VARCHAR(32) NOT NULL DEFAULT ''");
    $db->table($shares)->where('id', $converted)->update(['role_key' => 'reader']);

    shareLinksMigration()->up();

    expect($db->table($shares)->where('id', $converted)->value('role_key'))->toBe('reader')
        ->and($db->table($shares)->where('id', $pending)->value('role_key'))->toBe('commenter')
        ->and($db->table($shares)->where('id', $pending)->value('audience'))->toBe('authenticated');
});

it('stops on a column it did not create', function (): void {
    $db = shareLinksPrepare($this);
    $shares = app(DatabaseConfig::class)->table('node_shares');
    $db->getSchemaBuilder()->table($shares, static fn (Blueprint $table) => $table->text('audience')->nullable());
    $db->getSchemaBuilder()->table($shares, static fn (Blueprint $table) => $table->boolean('token_generation')->default(false));

    expect(fn () => shareLinksMigration()->up())->toThrow(UpgradePreflightException::class, 'token_generation');
});

it('rolls back to the mask, level and require_login it replaced', function (): void {
    $db = shareLinksPrepare($this);
    $nodeId = shareLinksNode();
    $shares = app(DatabaseConfig::class)->table('node_shares');
    $reader = shareLinksRow($db, $nodeId, 'reader-link', 65);
    $login = shareLinksRow($db, $nodeId, 'login-link', 97, 1, true);
    $preview = shareLinksRow($db, $nodeId, 'preview-link', 1);
    shareLinksMigration()->up();
    $db->table('migrations')->insert(['migration' => SHARE_LINKS_MIGRATION, 'batch' => 99]);

    shareLinksMigration()->down();

    $row = static fn (int|string $id): object => $db->table($shares)->where('id', $id)->first(['permissions', 'permissions_version', 'level', 'require_login']);
    expect((int) $row($reader)->permissions)->toBe(65)
        ->and((int) $row($login)->permissions)->toBe(97)
        ->and((bool) $row($login)->require_login)->toBeTrue()
        ->and((bool) $row($reader)->require_login)->toBeFalse()
        ->and((int) $row($preview)->permissions)->toBe(1)
        ->and($row($preview)->level)->toBe('previewer')
        ->and((int) $row($preview)->permissions_version)->toBe(1)
        ->and($db->getSchemaBuilder()->hasColumn($shares, 'role_key'))->toBeFalse();
});

it('refuses to roll back a tenant link, which the previous shape cannot hold', function (): void {
    $db = shareLinksPrepare($this);
    $nodeId = shareLinksNode();
    $shares = app(DatabaseConfig::class)->table('node_shares');
    $tenant = shareLinksRow($db, $nodeId, 'tenant-link', 65);
    shareLinksMigration()->up();
    $db->table($shares)->where('id', $tenant)->update(['audience' => 'tenant']);
    $db->table('migrations')->insert(['migration' => SHARE_LINKS_MIGRATION, 'batch' => 99]);

    expect(fn () => shareLinksMigration()->down())->toThrow(UpgradePreflightException::class, (string) $tenant)
        ->and($db->getSchemaBuilder()->hasColumn($shares, 'role_key'))->toBeTrue();
});

it('is a no-op on a fresh install, where the base shell already created the final shape', function (): void {
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $db = app(VaulterDatabase::class)->connection();
    $shares = app(DatabaseConfig::class)->table('node_shares');
    $columns = $db->getSchemaBuilder()->getColumnListing($shares);

    shareLinksMigration()->up();
    shareLinksMigration()->down();

    expect($db->getSchemaBuilder()->getColumnListing($shares))->toBe($columns)
        ->and($columns)->toContain('role_key', 'audience', 'token_generation');
});
