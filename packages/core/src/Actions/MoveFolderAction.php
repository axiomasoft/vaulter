<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\Models\DocumentFolder;
use DocVault\Repositories\FolderStoreRepository;
use DocVault\Support\Identity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class MoveFolderAction
{
    public function __construct(
        private FolderStoreRepository $folders,
    ) {}

    public function execute(DocumentFolder $folder, int|string|null $parentId): DocumentFolder
    {
        if ($parentId !== null && Identity::stringify($parentId) === Identity::stringify($folder->getKey())) {
            throw ValidationException::withMessages([
                'parent_id' => 'A folder cannot be its own parent.',
            ]);
        }

        return DB::transaction(fn (): DocumentFolder => $this->folders->move($folder, $parentId));
    }
}
