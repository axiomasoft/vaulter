<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Export;

use Illuminate\Foundation\Application;
use Override;
use Vaulter\Documents\Tests\Fixtures\Types\AcmeKanbanType;
use Vaulter\Documents\Tests\Fixtures\Types\OpaqueNoteType;
use Vaulter\Documents\Tests\Operations\OperationsTestCase;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;

/**
 * The Documents API with database grants, plus an opaque note and a third-party type
 * that no built-in exporter renders.
 */
abstract class ExportTestCase extends OperationsTestCase
{
    /**
     * @param Application $app
     */
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vaulter-documents.types', [
            RichTextDocumentType::class,
            MarkdownDocumentType::class,
            OpaqueNoteType::class,
            AcmeKanbanType::class,
        ]);
    }
}
