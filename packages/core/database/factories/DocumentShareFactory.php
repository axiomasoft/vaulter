<?php

declare(strict_types=1);

namespace DocVault\Database\Factories;

use DocVault\Enums\PermissionLevel;
use DocVault\Models\Document;
use DocVault\Models\DocumentShare;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
