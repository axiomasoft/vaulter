<?php

declare(strict_types=1);

namespace Vaulter\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Vaulter\Enums\PermissionLevel;
use Vaulter\Models\Document;
use Vaulter\Models\DocumentShare;

/**
 * @extends Factory<DocumentShare>
 */
final class DocumentShareFactory extends Factory
{
    protected $model = DocumentShare::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'token' => Str::random(48),
            'permission' => PermissionLevel::View,
            'expires_at' => null,
            'created_by' => null,
        ];
    }

    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }
}
