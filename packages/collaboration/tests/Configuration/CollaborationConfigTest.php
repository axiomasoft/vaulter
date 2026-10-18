<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Vaulter\Collaboration\Configuration\CollaborationConfig;
use Vaulter\Collaboration\Data\RoomRef;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

/** @param array<string, mixed> $values the `vaulter-collaboration` root, plus `documents` for the `vaulter-documents` root */
function config_of(array $values = [], array $documents = []): CollaborationConfig
{
    $repository = new Repository(['vaulter-collaboration' => $values, 'vaulter-documents' => $documents]);

    return new CollaborationConfig($repository, new DocumentsConfig($repository));
}

it('reads the shipped defaults of the vaulter-collaboration root', function (): void {
    $config = config_of(require __DIR__.'/../../config/vaulter-collaboration.php');

    expect($config->driver())->toBe('hocuspocus')
        ->and($config->hubUrl())->toBe('ws://localhost:1234')
        ->and($config->hubTokenTtl())->toBe(300)
        ->and($config->hubClockSkew())->toBe(30)
        ->and($config->controlTransport())->toBe('none')
        ->and($config->controlRedisConnection())->toBe('default')
        ->and($config->controlHttpUrls())->toBe([])
        ->and($config->controlHttpTimeout())->toBe(2)
        ->and($config->hubTimestampTolerance())->toBe(300)
        ->and($config->maxStateBytes())->toBe(16777216)
        ->and($config->channelPrefix())->toBe('vaulter.documents');
});

it('reads the secrets of the hub and treats an empty one as missing', function (): void {
    $config = config_of(['hub' => ['jwt_secret' => 'jwt', 'webhook_secret' => 'webhook', 'token_ttl' => 120]]);

    expect($config->hubJwtSecret())->toBe('jwt')
        ->and($config->hubWebhookSecret())->toBe('webhook')
        ->and($config->hubTokenTtl())->toBe(120)
        ->and(config_of(['hub' => ['jwt_secret' => '', 'webhook_secret' => 5]])->hubJwtSecret())->toBeNull()
        ->and(config_of(['hub' => ['jwt_secret' => '', 'webhook_secret' => 5]])->hubWebhookSecret())->toBeNull();
});

it('round trips room identities under the channel prefix of the documents', function (): void {
    $config = config_of(documents: ['broadcast' => ['channel_prefix' => 'host.docs']]);

    $room = $config->room('01DRIVE', '01ABC', 3);

    expect($room)->toBe('host.docs.01DRIVE.01ABC.3')
        ->and($config->parseRoom($room))->toEqual(new RoomRef(driveId: '01DRIVE', nodeId: '01ABC', epoch: 3));
});

it('parses only room names it issues', function (string $room): void {
    expect(config_of(documents: ['broadcast' => ['channel_prefix' => 'host.docs']])->parseRoom($room))->toBeNull();
})->with([
    'foreign prefix' => ['foreign.room.1.2.0'],
    'no epoch (0.2 room)' => ['host.docs.42'],
    'missing drive' => ['host.docs.42.0'],
    'extra segment' => ['host.docs.1.2.3.4'],
    'empty node' => ['host.docs.1..0'],
    'negative epoch' => ['host.docs.1.2.-1'],
    'padded epoch' => ['host.docs.1.2.01'],
    'non-numeric epoch' => ['host.docs.1.2.x'],
]);

it('refuses the removed reverb driver and a token ttl above five minutes', function (): void {
    $ttl = static fn (int $seconds): CollaborationConfig => config_of(['hub' => ['token_ttl' => $seconds]]);

    expect(fn () => config_of(['driver' => 'reverb'])->driver())->toThrow(InvalidConfigurationException::class, 'reverb collaboration driver was removed')
        ->and(fn () => $ttl(301)->hubTokenTtl())->toThrow(InvalidConfigurationException::class)
        ->and(fn () => $ttl(0)->hubTokenTtl())->toThrow(InvalidConfigurationException::class)
        ->and($ttl(300)->hubTokenTtl())->toBe(300)
        ->and(config_of()->maxStateBytes())->toBe(16777216)
        ->and(config_of(['max_state_bytes' => 0])->maxStateBytes())->toBe(16777216)
        ->and(config_of(['max_state_bytes' => 8])->maxStateBytes())->toBe(8);
});

it('keeps a timestamp tolerance of at least one second', function (): void {
    expect(config_of(['hub' => ['timestamp_tolerance' => 0]])->hubTimestampTolerance())->toBe(1)
        ->and(config_of()->hubTimestampTolerance())->toBe(300);
});
