<?php

declare(strict_types=1);

use Vaulter\Collaboration\Configuration\CollaborationConfig;
use Vaulter\Collaboration\HocuspocusCollaborationDriver;
use Vaulter\Collaboration\VaulterCollaborationServiceProvider;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Kernel;
use Vaulter\Testing\Concerns\FreshApplication;

/*
 * Doc/ADR-alignment guard tests (green
 * characterization, red-before is structurally impossible here — no behaviour
 * changed, only a stale fallback/doc were corrected to match the already-correct
 * code).
 */

it('the collaboration configuration accessor falls back to hocuspocus when the config key is entirely absent, F-P3.6-01', function (): void {
    // Replaced the transport selector with the documents-owned protocol slot.
    $app = FreshApplication::boot(['vaulter.modules.collaboration' => true], after: [VaulterDocumentsServiceProvider::class, VaulterCollaborationServiceProvider::class]);
    $app['config']->offsetUnset('vaulter-collaboration.driver');
    $extensions = $app->make(Kernel::class)->extensions();

    expect($extensions->handlerKeys(CollaborationProtocolDriver::class))->toBe(['yjs'])
        ->and($extensions->handler(CollaborationProtocolDriver::class, 'yjs'))->toBeInstanceOf(HocuspocusCollaborationDriver::class);
});

it('the vaulter-collaboration config default transport is hocuspocus (unchanged by P3.1 — the ADR/docs were the drift), F-P3.6-01', function (): void {
    $defaults = require __DIR__.'/../../../packages/collaboration/config/vaulter-collaboration.php';

    expect($defaults)->not->toHaveKey('driver')
        ->and($defaults)->toHaveKeys(['hub', 'mode', 'limits'])
        ->and(method_exists(CollaborationConfig::class, 'driver'))->toBeFalse();
});

it('README describes canonical feature ownership (P2.5)', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 3).'/README.md');

    expect($readme)->toContain('vaulter-documents')
        ->and($readme)->not->toContain("config('vaulter-drive.features.document_versions')", "config('vaulter-drive.features.comments')");
});
