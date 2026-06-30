<?php

declare(strict_types=1);

namespace Vaulter\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Vaulter\Models\Documents\DocumentFolder;

/**
 * @extends Factory<DocumentFolder>
 */
final class DocumentFolderFactory extends Factory
{
    protected $model = DocumentFolder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_type' => 'tests',
            'workspace_id' => 1,
            'parent_id' => null,
            'name' => fake()->words(2, true),
            'order' => 0,
        ];
    }
}
