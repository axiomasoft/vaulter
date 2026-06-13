<?php

declare(strict_types=1);

use DocVault\Collaboration\HocuspocusCollaborationDriver;
use DocVault\Collaboration\ReverbCollaborationDriver;
use DocVault\Exceptions\DocVaultException;
use DocVault\Facades\DocVault;
use DocVault\Models\Document;
use DocVault\Tests\Fixtures\DenyResolver;
use DocVault\Tests\Fixtures\TestUser;

it('defaults to the reverb driver', function (): void {
    expect(DocVault::driver())->toBeInstanceOf(ReverbCollaborationDriver::class);
});

it('resolves the hocuspocus driver by name', function (): void {
    expect(DocVault::driver('hocuspocus'))->toBeInstanceOf(HocuspocusCollaborationDriver::class);
});

it('throws on an unknown driver', function (): void {
    DocVault::driver('nope');
})->throws(DocVaultException::class);

it('builds a reverb connection config', function (): void {
    $document = Document::factory()->create();
    $user = (new TestUser)->forceFill(['id' => 1]);

    $config = DocVault::driver()->connectionConfig($document, $user);

    expect($config['driver'])->toBe('reverb')
        ->and($config['channel'])->toContain('docvault.documents.')
        ->and($config['token'])->toBe('');
});

it('denies the channel when the resolver denies edit', function (): void {
    DocVault::resolvePermissionsWith(new DenyResolver);
    $document = Document::factory()->create();
    $user = (new TestUser)->forceFill(['id' => 1]);

    expect(DocVault::driver()->authorizeChannel($user, $document))->toBeFalse();
});
