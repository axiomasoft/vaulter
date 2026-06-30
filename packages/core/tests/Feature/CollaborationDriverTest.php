<?php

declare(strict_types=1);

use Vaulter\Collaboration\HocuspocusCollaborationDriver;
use Vaulter\Collaboration\ReverbCollaborationDriver;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Document;
use Vaulter\Tests\Fixtures\DenyResolver;
use Vaulter\Tests\Fixtures\TestUser;

it('defaults to the reverb driver', function (): void {
    expect(Vaulter::driver())->toBeInstanceOf(ReverbCollaborationDriver::class);
});

it('resolves the hocuspocus driver by name', function (): void {
    expect(Vaulter::driver('hocuspocus'))->toBeInstanceOf(HocuspocusCollaborationDriver::class);
});

it('throws on an unknown driver', function (): void {
    Vaulter::driver('nope');
})->throws(VaulterException::class);

it('builds a reverb connection config', function (): void {
    $document = Document::factory()->create();
    $user = (new TestUser)->forceFill(['id' => 1]);

    $config = Vaulter::driver()->connectionConfig($document, $user);

    // Reverb authenticates out-of-band (the private channel auth endpoint), so the
    // "token" the client uses IS the channel name it subscribes to — a usable handle
    // for both transports rather than an opaque empty string (M4b deliverable #1).
    expect($config['driver'])->toBe('reverb')
        ->and($config['channel'])->toContain('vaulter.documents.')
        ->and($config['connection'])->toBe('reverb')
        ->and($config['token'])->toBe($config['channel']);
});

it('denies the channel when the resolver denies edit', function (): void {
    Vaulter::resolvePermissionsWith(new DenyResolver);
    $document = Document::factory()->create();
    $user = (new TestUser)->forceFill(['id' => 1]);

    expect(Vaulter::driver()->authorizeChannel($user, $document))->toBeFalse();
});
