<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Collaboration;

use Illuminate\Foundation\Application;
use Vaulter\Documents\Tests\Document\DocumentTestCase;

/**
 * Boots the package with BOTH 'document_versions' AND 'collaboration' ENABLED
 * (VLT-REMED P3.1 / F-P1.6-01, D13/D20): the realtime surface is now a SEPARATE
 * opt-in on top of document_versions, and — like every other feature flag in this
 * suite — it must be flipped in `defineEnvironment()` (BEFORE the app/routes boot),
 * not in a test's `beforeEach()` (routes are already registered by then). Kept in
 * its OWN top-level tests/Collaboration folder (Pest forbids two test cases for one
 * path) so the plain `DocumentTestCase` binding on tests/Document (collaboration
 * OFF) never also claims these files — the item-gate test
 * (`CollaborationFeatureGateTest`, under tests/Document) asserts the true default
 * (both flags independent, collaboration off) undisturbed.
 */
abstract class CollaborationEnabledTestCase extends DocumentTestCase
{
    /**
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vaulter-documents.collaboration.enabled', true);
    }
}
