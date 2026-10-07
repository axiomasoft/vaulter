<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Document;

use Illuminate\Foundation\Application;
use Vaulter\Documents\Tests\Concerns\PreparesDocumentVersionSchema;
use Vaulter\Documents\Tests\TestCase;

/**
 * Boots the package with the Documents 'document_versions' feature ENABLED so the
 * v_documents/v_document_revisions schema is migrated and the document-node Actions/HTTP are
 * exercised (§2.5 / M4a). Kept in its own top-level folder so the base binding never
 * also claims it (Pest forbids two test cases for one path).
 */
abstract class DocumentTestCase extends TestCase
{
    use PreparesDocumentVersionSchema;

    /**
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Opt INTO the node-attached document version schema + routes (default off).
    }
}
