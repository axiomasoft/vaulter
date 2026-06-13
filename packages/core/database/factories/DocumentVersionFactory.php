<?php

declare(strict_types=1);

namespace DocVault\Database\Factories;

use DocVault\Models\Document;
use DocVault\Models\DocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentVersion>
 */
final class DocumentVersionFactory extends Factory
{
    protected $model = DocumentVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'content' => ['type' => 'doc', 'content' => []],
            'snapshot_label' => null,
            'created_by' => null,
        ];
    }
}
