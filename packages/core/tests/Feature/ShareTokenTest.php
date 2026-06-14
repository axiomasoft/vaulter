<?php

declare(strict_types=1);

use DocVault\Contracts\ShareTokenGenerator;
use DocVault\Facades\DocVault;
use DocVault\Models\Document;
use DocVault\Tests\Fixtures\AllowResolver;
use DocVault\Tests\Fixtures\TestUser;

it('mints share tokens with a swapped generator', function (): void {
    app()->bind(ShareTokenGenerator::class, fn (): ShareTokenGenerator => new class implements ShareTokenGenerator
    {
        public function generate(): string
        {
            return 'fixed-share-token';
        }
    });

    DocVault::resolvePermissionsWith(new AllowResolver);
    $this->actingAs((new TestUser)->forceFill(['id' => 11]));
    $document = Document::factory()->create();

    $this->postJson(sprintf('api/docvault/documents/%s/shares', $document->id), ['permission' => 'edit'])
        ->assertCreated()
        ->assertJsonPath('data.token', 'fixed-share-token');
});

it('defaults to a 48-character random token', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    $this->actingAs((new TestUser)->forceFill(['id' => 11]));
    $document = Document::factory()->create();

    $token = $this->postJson(sprintf('api/docvault/documents/%s/shares', $document->id), ['permission' => 'view'])
        ->assertCreated()
        ->json('data.token');

    expect($token)->toBeString()->and(strlen((string) $token))->toBe(48);
});
