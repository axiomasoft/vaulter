<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

it('writes nothing to the database, the cache or the disks', function (): void {
    Storage::fake('local');
    $drive = DriveTestHelpers::drive();
    $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('payload'), 'doc.txt'), new UploadOptions(declaredMimeType: 'text/plain'));
    $disk = Storage::disk('local');
    $before = $disk->allFiles();

    $statements = [];
    DB::listen(static function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });
    Artisan::call('vaulter:doctor', ['--json' => true]);
    Artisan::call('vaulter:doctor', ['--json' => true, '--sample' => '5']);

    $writes = array_values(array_filter($statements, static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|create|alter|drop|replace|truncate)\b/i', $sql) === 1));

    expect($statements)->not->toBeEmpty()
        ->and($writes)->toBe([])
        ->and($disk->allFiles())->toBe($before);
});

it('does not open a write scope', function (): void {
    expect(file_exists(__DIR__.'/../../src/Doctor/DoctorRunner.php'))->toBeTrue();
    foreach (glob(__DIR__.'/../../src/Doctor/{*,Checks/*}.php', GLOB_BRACE) ?: [] as $file) {
        expect(file_get_contents($file))->not->toContain('OpensWriteScope')->not->toContain('->write(')->not->toContain('->transaction(');
    }
});
