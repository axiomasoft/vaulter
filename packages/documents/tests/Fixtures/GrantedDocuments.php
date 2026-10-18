<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures;

use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

/**
 * A document under the real database grants: the drive, the document node and the actor's role on it. The REST and
 * operations suites build their subjects with it.
 */
final class GrantedDocuments
{
    /** Switches the suite from the open permission driver to the grant-based one. */
    public static function configure(): void
    {
        config()->set('vaulter-drive.access.permission_driver', 'database');
    }

    public static function document(
        ?string $level = RoleRegistry::WRITER,
        int $actorId = 1,
        ?DocumentContent $content = null,
        string $type = 'vaulter/rich-text',
        int $schemaVersion = 1,
    ): Node {
        $drive = DocumentTestHelpers::drive();

        $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
            storage: $drive->model(),
            name: 'Spec',
            content: $content ?? DocumentTestHelpers::content('Draft'),
            parent: DriveTestHelpers::model($drive->root()),
            type: $type,
            schemaVersion: $schemaVersion,
        ));

        if ($level !== null) {
            AccessTestHelpers::grant($node, $level, subjectId: $actorId);
        }

        return $node;
    }

    public static function aggregate(Node $node): Document
    {
        return Document::query()->where('node_id', $node->getKey())->firstOrFail();
    }

    /** @return array<string, mixed> a TipTap document with one paragraph */
    public static function tiptap(string $text): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
    }
}
