<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Access;

use Vaulter\Data\GrantPermissionData;
use Vaulter\Models\Documents\Document;
use Vaulter\Models\Documents\DocumentPermission;
use Vaulter\Support\Config;

final class PermissionStoreRepository
{
    public function grant(Document $document, GrantPermissionData $data): DocumentPermission
    {
        $model = Config::permissionModel();

        return $model::query()->updateOrCreate(
            attributes: [
                'document_id' => $document->getKey(),
                'subject_type' => $data->subjectType,
                'subject_id' => $data->subjectId,
            ],
            values: [
                'permission' => $data->level,
                'granted_by' => $data->grantedBy,
            ],
        );
    }

    public function revoke(Document $document, string $subjectType, int|string $subjectId): int
    {
        $model = Config::permissionModel();

        $deleted = $model::query()
            ->where('document_id', $document->getKey())
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->delete();

        return is_int($deleted) ? $deleted : 0;
    }
}
