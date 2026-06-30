<?php

declare(strict_types=1);

use Vaulter\Collaboration\HocuspocusCollaborationDriver;
use Vaulter\Collaboration\ReverbCollaborationDriver;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Documents\Document;
use Vaulter\Tests\Fixtures\DenyResolver;
use Vaulter\Tests\Fixtures\TestUser;

it('defaults to the hocuspocus driver', function (): void {
    // Hocuspocus is the recommended Yjs collaboration backend; reverb is deprecated
    // for document editing (it cannot enforce read-only / debounce persistence).
    expect(Vaulter::driver())->toBeInstanceOf(HocuspocusCollaborationDriver::class);
});

it('resolves the hocuspocus driver by name', function (): void {
    expect(Vaulter::driver('hocuspocus'))->toBeInstanceOf(HocuspocusCollaborationDriver::class);
});

it('still resolves the reverb driver by name (deprecated transport, kept for BC)', function (): void {
    expect(Vaulter::driver('reverb'))->toBeInstanceOf(ReverbCollaborationDriver::class);
});

it('throws on an unknown driver', function (): void {
    Vaulter::driver('nope');
})->throws(VaulterException::class);

it('builds a reverb connection config', function (): void {
    $document = Document::factory()->create();
    $user = (new TestUser)->forceFill(['id' => 1]);

    $config = Vaulter::driver('reverb')->connectionConfig($document, $user);

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

    expect(Vaulter::driver('reverb')->authorizeChannel($user, $document))->toBeFalse();
});
