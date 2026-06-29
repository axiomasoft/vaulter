<?php

declare(strict_types=1);

use Vaulter\Contracts\ShareTokenGenerator;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Document;
use Vaulter\Tests\Fixtures\AllowResolver;
use Vaulter\Tests\Fixtures\TestUser;

it('mints share tokens with a swapped generator', function (): void {
    app()->bind(ShareTokenGenerator::class, fn (): ShareTokenGenerator => new class implements ShareTokenGenerator
    {
        public function generate(): string
        {
            return 'fixed-share-token';
        }
    });

    Vaulter::resolvePermissionsWith(new AllowResolver);
    $this->actingAs((new TestUser)->forceFill(['id' => 11]));
    $document = Document::factory()->create();

    $this->postJson(sprintf('api/vaulter/documents/%s/shares', $document->id), ['permission' => 'edit'])
        ->assertCreated()
        ->assertJsonPath('data.token', 'fixed-share-token');
});

it('defaults to a 48-character random token', function (): void {
    Vaulter::resolvePermissionsWith(new AllowResolver);
    $this->actingAs((new TestUser)->forceFill(['id' => 11]));
    $document = Document::factory()->create();

    $token = $this->postJson(sprintf('api/vaulter/documents/%s/shares', $document->id), ['permission' => 'view'])
        ->assertCreated()
        ->json('data.token');

    expect($token)->toBeString()->and(strlen((string) $token))->toBe(48);
});
