<?php

declare(strict_types=1);

namespace Vaulter\Plans\AuditVlt\Repro\P35;

use Illuminate\Contracts\Container\BindingResolutionException;
use Vaulter\Contracts\Documents\HandlerRegistry;
use Vaulter\Contracts\Drive\DriveHandleFactory;
use Vaulter\Facades\Vaulter;
use Vaulter\Tests\TestCase;

/**
 * P3.5 repro (C5 — Implementation Rules): the core package's own TestCase
 * (packages/core/tests/TestCase.php) does NOT list
 * VaulterDocumentsServiceProvider / VaulterDriveServiceProvider is present
 * (drive IS present — see below) but VaulterDocumentsServiceProvider is
 * ABSENT among its getPackageProviders(). This reproduces "vaulter-documents
 * not installed" and asserts WHAT the actual exception/message is when
 * VaulterManager::handlers()/registerHandler() are called — the docblocks
 * claim these "fail loudly ... document handling needs the documents
 * package" (C5 requires the fact be confirmed by the manager body, not the
 * comment).
 *
 * Runs directly against the package TestCase (not via Pest uses()-binding,
 * since Pest only auto-loads Pest.php from the project's configured "tests"
 * directory — plans/AUDIT-VLT/artifacts/repro/P3.5 is outside it, D6).
 */
final class DriverSeamFailLoudTest extends TestCase
{
    public function test_c5_handler_registry_seam_fails_loud_without_an_install_hint(): void
    {
        // Control: vaulter-drive IS registered in core's TestCase (P3.5
        // Required Read #14), so the sibling seam resolves normally — the
        // fixture is sound, only the documents seam is deliberately absent.
        self::assertTrue($this->app->bound(DriveHandleFactory::class));
        self::assertFalse($this->app->bound(HandlerRegistry::class));

        try {
            Vaulter::handlers();
            self::fail('expected BindingResolutionException, none thrown');
        } catch (BindingResolutionException $e) {
            fwrite(STDERR, "\n[C5 repro] handlers() exception class: ".$e::class."\n");
            fwrite(STDERR, "[C5 repro] handlers() message: ".$e->getMessage()."\n");

            // The message must contain NEITHER "[Vaulter]" (the package's own
            // install-hint exception prefix, see
            // MissingHostRoleDriverException::unknownDriver) NOR
            // "vaulter-documents" (the actual fix a host needs to apply).
            self::assertStringNotContainsString('[Vaulter]', $e->getMessage());
            self::assertStringNotContainsString('vaulter-documents', $e->getMessage());
        }

        try {
            Vaulter::registerHandler(\Vaulter\Documents\Documents\Handlers\UniverHandler::class);
            self::fail('expected BindingResolutionException, none thrown');
        } catch (BindingResolutionException $e) {
            fwrite(STDERR, "[C5 repro] registerHandler() message: ".$e->getMessage()."\n");
            self::assertStringNotContainsString('[Vaulter]', $e->getMessage());
        }
    }
}
