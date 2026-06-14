<?php

declare(strict_types=1);

namespace DocVault\Repositories;

use DocVault\Exceptions\DocumentNotFoundException;
use DocVault\Models\Document;
use DocVault\Support\Config;
use DocVault\ValueObjects\WorkspaceRef;
use Illuminate\Database\Eloquent\Builder;

final class DocumentReadRepository
{
    /**
     * @return Builder<Document>
     */
    public function query(): Builder
    {
        $model = Config::documentModel();

        return $model::query();
    }

    /**
     * @return Builder<Document>
     */
    public function forWorkspace(WorkspaceRef $workspace): Builder
    {
        return $this->query()->forWorkspace($workspace);
    }

    public function find(int|string $id): ?Document
    {
        return $this->query()->find($id);
    }

    public function findWithTrashed(int|string $id): ?Document
    {
        return $this->query()->withTrashed()->find($id);
    }

    public function findOrFail(int|string $id): Document
    {
        return $this->find($id) ?? throw DocumentNotFoundException::withId($id);
    }
}
