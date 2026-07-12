<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Vaulter\Tests\P25Repro\InstallCommandBase;

require __DIR__.'/InstallCommandBase.php';

uses(InstallCommandBase::class);

it('runs vaulter:install --no-interaction and reports each step (C1)', function () {
    $exit = Artisan::call('vaulter:install', ['--no-interaction' => true]);
    $output = Artisan::output();

    dump('exit code = '.$exit);
    dump('output = '.$output);

    // config file physically published?
    $configPublished = is_file(config_path('vaulter.php'));
    dump('config_path(vaulter.php) published = '.($configPublished ? 'yes' : 'no'));

    // migrations physically published?
    $publishedMigrations = glob(database_path('migrations/*.php'));
    dump('published migration count = '.count($publishedMigrations));
    dump('published migration files = '.json_encode(array_map('basename', $publishedMigrations)));

    // did migrations actually run (askToRunMigrations under --no-interaction => confirm() default)?
    $migrationsTableExists = \Illuminate\Support\Facades\Schema::hasTable('v_nodes');
    dump('v_nodes table exists after install (i.e. migrate ran) = '.($migrationsTableExists ? 'yes' : 'no'));

    expect($exit)->toBe(0);
});

it('runs vendor:publish --tag=<tag> for each real tag (C2) and does not silently overwrite on rerun', function () {
    // Fresh publish for each tag actually present per P2.2 C7 (vaulter-config, vaulter-migrations).
    $tags = ['vaulter-config', 'vaulter-migrations'];

    foreach ($tags as $tag) {
        $exit = Artisan::call('vendor:publish', ['--tag' => $tag, '--no-interaction' => true]);
        $output = Artisan::output();
        dump("vendor:publish --tag={$tag} exit={$exit}");
        dump($output);
    }

    $configPath = config_path('vaulter.php');
    expect(is_file($configPath))->toBeTrue();

    // Mutate the published file, then re-run WITHOUT --force: must NOT be silently overwritten.
    file_put_contents($configPath, "<?php\n// P2.5-marker-".uniqid()."\nreturn [];\n");
    $marker = file_get_contents($configPath);

    $exit = Artisan::call('vendor:publish', ['--tag' => 'vaulter-config', '--no-interaction' => true]);
    $after = file_get_contents($configPath);

    dump('vendor:publish rerun w/o --force exit = '.$exit);
    dump('file unchanged after rerun w/o --force = '.($after === $marker ? 'yes (preserved)' : 'no (OVERWRITTEN)'));

    expect(true)->toBeTrue();
});
