<?php

declare(strict_types=1);

namespace DocVault\Database\Factories;

use DocVault\Models\Document;
use DocVault\Models\DocumentComment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentComment>
 */
final class DocumentCommentFactory extends Factory
{
    protected $model = DocumentComment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'parent_id' => null,
            'author_type' => 'tests-user',
            'author_id' => 1,
            'body' => fake()->sentence(),
            'anchor' => null,
            'resolved_at' => null,
        ];
    }
}
