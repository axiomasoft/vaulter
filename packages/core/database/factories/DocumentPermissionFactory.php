<?php

declare(strict_types=1);

namespace DocVault\Database\Factories;

use DocVault\Enums\PermissionLevel;
use DocVault\Models\Document;
use DocVault\Models\DocumentPermission;
use Illuminate\Database\Eloquent\Factories\Factory;

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
