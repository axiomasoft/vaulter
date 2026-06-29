<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Data\CreateVersionData;
use Vaulter\Events\VersionCreated;
use Vaulter\Models\Document;
use Vaulter\Models\DocumentVersion;
use Vaulter\Repositories\VersionStoreRepository;
use Vaulter\Support\Config;

final readonly class CreateVersionAction
{
    public function __construct(
        private VersionStoreRepository $versions,
    ) {}

    public function execute(Document $document, CreateVersionData $data): DocumentVersion
    {
        $version = DB::transaction(function () use ($document, $data): DocumentVersion {
            $version = $this->versions->create([
                'document_id' => $document->getKey(),
                'content' => $document->content ?? ['type' => 'doc', 'content' => []],
                'snapshot_label' => $data->snapshotLabel,
                'created_by' => $data->actorId,
            ]);

            $this->versions->pruneOldest($document, Config::maxVersions());

            return $version;
        });

        event(new VersionCreated($version, $data->actorId));

        return $version;
    }
}
