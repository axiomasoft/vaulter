<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Data\Snapshots\ActivityEntrySnapshot;
use Vaulter\Data\Snapshots\GrantSnapshot;
use Vaulter\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Sharing\ShareAudience;
use Vaulter\Http\Resources\Activity\ActivityResource;
use Vaulter\Http\Resources\Drive\NodeGrantResource;
use Vaulter\Http\Resources\Drive\NodeResource;
use Vaulter\Http\Resources\Sharing\NodeShareResource;
use Vaulter\Tests\Drive\DriveTestHelpers;

it('serializes snapshot timestamps as UTC strings and keeps absent timestamps null', function (): void {
    $time = new DateTimeImmutable('2026-10-08T12:34:56.123456+03:00');
    $expected = '2026-10-08T09:34:56.123456Z';
    $request = Request::create('/api/vaulter/nodes');
    $drive = DriveTestHelpers::drive();
    $node = $drive->folders()->create('Reports');
    $nodePayload = (new NodeResource($node))->resolve($request);
    $grant = new GrantSnapshot(id: '1', nodeId: $node->id, subject: new Subject('user', '2'), role: 'reader', roleVersion: 1, scope: GrantScope::Item, expiresAt: $time, createdAt: $time, grantedBy: null);
    $share = new ShareLinkSnapshot(id: '1', nodeId: $node->id, role: 'reader', roleVersion: 1, audience: ShareAudience::Anyone, scope: GrantScope::Item, expiresAt: $time, passwordProtected: false, maxUses: null, useCount: 0, tokenPrefix: 'prefix', tokenGeneration: 1, revokedAt: null, createdAt: $time, createdBy: null);
    $activity = new ActivityEntrySnapshot(id: '1', verb: 'node.created', driveId: $node->driveId, targetType: 'node', targetId: $node->id, actorType: null, actorId: null, context: [], ip: null, userAgent: null, requestId: null, createdAt: $time);
    $grantPayload = (new NodeGrantResource($grant))->resolve($request);
    $sharePayload = (new NodeShareResource($share))->resolve($request);
    $activityPayload = (new ActivityResource($activity))->resolve($request);

    expect($nodePayload['created_at'])->toBeString()
        ->and($nodePayload['updated_at'])->toBeString()
        ->and($nodePayload['trashed_at'])->toBeNull()
        ->and($nodePayload['content_modified_at'])->toBeNull()
        ->and($grantPayload['created_at'])->toBe($expected)
        ->and($grantPayload['expires_at'])->toBe($expected)
        ->and($sharePayload['created_at'])->toBe($expected)
        ->and($sharePayload['expires_at'])->toBe($expected)
        ->and($sharePayload['revoked_at'])->toBeNull()
        ->and($activityPayload['created_at'])->toBe($expected);
});
