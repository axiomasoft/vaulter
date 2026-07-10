<?php

declare(strict_types=1);

use Vaulter\Tests\VerifyFP22_02\DuplicateRunBase;

require __DIR__.'/DuplicateRunBase.php';

uses(DuplicateRunBase::class);

it('shows whether a fresh migrate() sees both the vendor-run migrations and a stale published copy under a new timestamp (F-P2.2-02)', function () {
    $publishedBefore = glob(database_path('migrations/*.php'));
    dump('database/migrations (default path, scanned unconditionally by Laravel) BEFORE test = '
        .json_encode(array_map('basename', $publishedBefore), JSON_PRETTY_PRINT));

    $exitCode = $this->artisan('migrate', ['--force' => true])->run();
    $output = \Illuminate\Support\Facades\Artisan::output();

    dump('migrate exit code = '.$exitCode);
    dump('migrate output = '.$output);

    $tables = \Illuminate\Support\Facades\DB::select("select name from sqlite_master where type='table'");
    dump('sqlite tables after migrate() = '.json_encode(array_map(static fn ($row) => $row->name, $tables), JSON_PRETTY_PRINT));

    // Не жёсткий assert по exit code — цель repro: задокументировать РЕАЛЬНОЕ
    // поведение (успех/провал), а не заранее закодированный вердикт.
    expect(true)->toBeTrue();
});
