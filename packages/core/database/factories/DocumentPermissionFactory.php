<?php

declare(strict_types=1);

namespace Vaulter\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Vaulter\Enums\PermissionLevel;
use Vaulter\Models\Document;
use Vaulter\Models\DocumentPermission;

/**
 * @extends Factory<DocumentPermission>
 */
final class DocumentPermissionFactory extends Factory
{
    protected $model = DocumentPermission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'subject_type' => 'tests-user',
            'subject_id' => 1,
            'permission' => PermissionLevel::View,
            'granted_by' => null,
        ];
    }
}
