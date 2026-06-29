<?php

declare(strict_types=1);

namespace Vaulter\Repositories;

use Vaulter\Contracts\ShareTokenGenerator;
use Vaulter\Data\CreateShareData;
use Vaulter\Models\Document;
use Vaulter\Models\DocumentShare;
use Vaulter\Support\Config;

final class ShareStoreRepository
{
    public function __construct(
        private readonly ShareTokenGenerator $tokens,
    ) {}

    public function create(Document $document, CreateShareData $data): DocumentShare
    {
        $model = Config::shareModel();
        $share = new $model;
        $share->fill([
            'document_id' => $document->getKey(),
            'token' => $this->tokens->generate(),
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
