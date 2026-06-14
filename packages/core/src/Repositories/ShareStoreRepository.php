<?php

declare(strict_types=1);

namespace DocVault\Repositories;

use DocVault\Data\CreateShareData;
use DocVault\Models\Document;
use DocVault\Models\DocumentShare;
use DocVault\Support\Config;
use Illuminate\Support\Str;

final class ShareStoreRepository
{
    public function create(Document $document, CreateShareData $data): DocumentShare
    {
        $model = Config::shareModel();
        $share = new $model;
        $share->fill([
            'document_id' => $document->getKey(),
            'token' => Str::random(48),
            'permission' => $data->level,
            'expires_at' => $data->expiresAt,
            'created_by' => $data->createdBy,
        ]);
        $share->save();

        return $share;
    }

    public function delete(DocumentShare $share): void
    {
        $share->delete();
    }
}
