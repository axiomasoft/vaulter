<?php

declare(strict_types=1);

namespace Vaulter\Repositories;

use Vaulter\Models\Document;
use Vaulter\Support\Config;

final class DocumentStoreRepository
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Document
    {
        $model = Config::documentModel();
        $document = new $model;
        $document->fill($attributes);
        $document->save();

        return $document;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(Document $document, array $attributes): Document
    {
        $document->fill($attributes);
        $document->save();

        return $document;
    }

    public function softDelete(Document $document): void
    {
        $document->delete();
    }

    public function restore(Document $document): void
    {
        $document->restore();
    }

    public function forceDelete(Document $document): void
    {
        $document->forceDelete();
    }
}
