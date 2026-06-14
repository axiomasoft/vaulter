<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\Data\CreateVersionData;
use DocVault\Events\VersionCreated;
use DocVault\Models\Document;
use DocVault\Models\DocumentVersion;
use DocVault\Repositories\VersionStoreRepository;
use DocVault\Support\Config;
use Illuminate\Support\Facades\DB;

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
