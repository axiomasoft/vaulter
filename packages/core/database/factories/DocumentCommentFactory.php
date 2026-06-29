<?php

declare(strict_types=1);

namespace Vaulter\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Vaulter\Models\Document;
use Vaulter\Models\DocumentComment;

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
