<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Vaulter\Models\Documents\DocumentFolder;
use Vaulter\Repositories\Documents\FolderStoreRepository;
use Vaulter\Support\Identity;

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
