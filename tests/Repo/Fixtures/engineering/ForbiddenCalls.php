<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;

function forbiddenEngineeringCalls(ZipArchive $archive): void
{
    DB::transaction(static fn (): null => null);
    $archive->extractTo('/tmp/unused');
    parse_url('https://example.test');
    env('UNUSED');
    class_exists(VerifyCsrfToken::class);
    DB::select('SELECT pg_advisory_lock(hashtext(?))', ['test']);
    DB::select("SELECT set_config('vaulter.tenant_id', ?, false)", ['test']);
    DB::statement('ALTER TABLE example ADD CONSTRAINT sample FOREIGN KEY (id) REFERENCES parent (id) DEFERRABLE INITIALLY DEFERRED');
}
