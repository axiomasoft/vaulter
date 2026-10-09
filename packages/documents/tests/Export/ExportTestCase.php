<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Export;

use Illuminate\Foundation\Application;
use Vaulter\Documents\Tests\Fixtures\Types\AcmeKanbanType;
use Vaulter\Documents\Tests\Operations\OperationsTestCase;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Documents\Types\SpreadsheetDocumentType;

/**
 * The Documents API with database grants, plus a spreadsheet and a third-party type
 * that no built-in exporter renders.
 */
abstract class ExportTestCase extends OperationsTestCase
{
    /**
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vaulter-documents.types', [
            RichTextDocumentType::class,
            MarkdownDocumentType::class,
            SpreadsheetDocumentType::class,
            AcmeKanbanType::class,
        ]);
    }
}
