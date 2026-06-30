<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Documents;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Exceptions\Documents\DocumentNotFoundException;
use Vaulter\Models\Documents\Document;
use Vaulter\Support\Config;
use Vaulter\ValueObjects\Drive\WorkspaceRef;

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

    /**
     * Search a workspace's documents by title and raw content. Case-folded so the
     * match is case-INSENSITIVE on every driver — a bare `like` is case-sensitive on
     * PostgreSQL (and case-insensitive on SQLite/MySQL), so "Roadmap" would slip past
     * a "roadmap" query on PG. Mirrors NodeReadRepository::search. Both columns are
     * text, so lower() applies portably.
     *
     * @return Builder<Document>
     */
    public function search(WorkspaceRef $workspace, string $term): Builder
    {
        $like = '%'.mb_strtolower($term).'%';

        return $this->forWorkspace($workspace)->where(function (Builder $query) use ($like): void {
            $query->whereRaw('lower(title) like ?', [$like])
                ->orWhereRaw('lower(content) like ?', [$like]);
        });
    }

    /**
     * @return Builder<Document>
     */
    public function trashedForWorkspace(WorkspaceRef $workspace): Builder
    {
        return $this->query()->onlyTrashed()->forWorkspace($workspace);
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
