<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Types;

use Illuminate\Foundation\Application;
use Vaulter\Documents\Tests\Document\DocumentTestCase;
use Vaulter\Documents\Tests\Fixtures\Types\AcmeKanbanType;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Documents\Types\SpreadsheetDocumentType;

/**
 * Boots documents with the built-in types, the spreadsheet type and a third-party
 * type (acme/kanban) registered from config, the way a host installs an extension.
 */
abstract class TypesTestCase extends DocumentTestCase
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

    /**
     * Replace the booted registry with one holding exactly $types — what the next
     * request sees after the host installs or removes an extension.
     *
     * @param list<class-string> $types
     */
    public static function installTypes(array $types): DocumentTypeRegistry
    {
        $registry = new DocumentTypeRegistry(app());
        foreach ($types as $type) {
            $registry->register($type);
        }
        $registry->freeze();
        app()->instance(DocumentTypeRegistry::class, $registry);

        return $registry;
    }
}
