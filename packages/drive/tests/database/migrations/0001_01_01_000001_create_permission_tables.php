<?php

declare(strict_types=1);

use Composer\InstalledVersions;

/**
 * spatie/laravel-permission's create_permission_tables migration, registered so
 * RefreshDatabase's migrate:fresh runs it once (committed) instead of the TestCase
 * calling up() by hand per test — the latter does not survive the per-test transaction
 * on a persistent connection (the pgsql lane) and re-runs into "relation already exists".
 *
 * The package ships it as a .php.stub (not auto-discoverable); it returns an anonymous
 * Migration whose up() reads config('permission.*') at run time, so the teams columns are
 * honoured because SpatieTestCase::defineEnvironment sets permission.teams first.
 */
// Resolved through Composer: the drive suite runs on its own packages/drive/vendor, not the repository's.
return require InstalledVersions::getInstallPath('spatie/laravel-permission')
    .'/database/migrations/create_permission_tables.php.stub';
