<?php

declare(strict_types=1);

namespace Vaulter\Documents\Repositories\Documents;

use Vaulter\Attributes\Internal;
use Vaulter\Documents\Models\Documents\Document;

/**
 * Row writes of the document aggregate. Only CreateDocumentAction (the empty
 * aggregate) and DocumentStateWriter (everything after) call it.
 */
#[Internal]
final class DocumentStoreRepository
{
    /**
     * @param array<string, mixed> $attributes server-built, never request input
     */
    public function create(array $attributes): Document
    {
        $document = new Document;
        $document->forceFill($attributes)->save();

        return $document->refresh();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(Document $document, array $attributes): Document
    {
        $document->forceFill($attributes)->save();

        return $document;
    }
}
