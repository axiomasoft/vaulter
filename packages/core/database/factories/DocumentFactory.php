<?php

declare(strict_types=1);

namespace DocVault\Database\Factories;

use DocVault\Enums\DocumentType;
use DocVault\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
final class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_type' => 'tests',
            'workspace_id' => 1,
            'folder_id' => null,
            'type' => DocumentType::RichText,
            'title' => fake()->sentence(3),
            'content' => ['type' => 'doc', 'content' => []],
            'meta' => [],
            'created_by' => null,
            'updated_by' => null,
        ];
    }
}
