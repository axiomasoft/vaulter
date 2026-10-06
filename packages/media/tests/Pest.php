<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Media\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Observability');

uses(TestCase::class)->in('Cas');
$unwrapped = ['BlobScopeKeyTest.php', 'ScopeKeyReconcileTest.php', 'RepairBlobScopesCommandTest.php'];
foreach (glob(__DIR__.'/Cas/*Test.php') as $file) {
    uses(in_array(basename($file), $unwrapped, true)
        ? DatabaseMigrations::class
        : RefreshDatabase::class)->in($file);
}

uses(TestCase::class)->in('Configuration');
