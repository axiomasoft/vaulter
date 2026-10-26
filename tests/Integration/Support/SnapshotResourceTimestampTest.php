<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Vaulter\Drive\Access\ValueObjects\NodeAbilities;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Snapshots\ActivityEntrySnapshot;
use Vaulter\Drive\Data\Snapshots\GrantSnapshot;
use Vaulter\Drive\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\Sharing\ShareAudience;
use Vaulter\Drive\Http\Resources\Access\GrantResource;
use Vaulter\Drive\Http\Resources\Activity\ActivityResource;
use Vaulter\Drive\Http\Resources\Drive\NodeResource;
use Vaulter\Drive\Http\Resources\Sharing\ShareResource;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

it('serializes snapshot timestamps as UTC strings and keeps absent timestamps null', function (): void {
    $time = new DateTimeImmutable('2026-10-08T12:34:56.123456+03:00');
    $expected = '2026-10-08T09:34:56.123456Z';
    $request = Request::create('/api/vaulter/v1/nodes');
    $drive = DriveTestHelpers::drive();
    $node = $drive->folders()->create('Reports');
    $nodePayload = (new NodeResource($node, NodeAbilities::denied()))->resolve($request);
    $grant = new GrantSnapshot(id: '1', nodeId: $node->id, subject: new Subject('user', '2'), role: 'reader', roleVersion: 1, scope: GrantScope::Item, expiresAt: $time, createdAt: $time, grantedBy: null);
    $share = new ShareLinkSnapshot(id: '1', nodeId: $node->id, role: 'reader', roleVersion: 1, audience: ShareAudience::Anyone, scope: GrantScope::Item, expiresAt: $time, passwordProtected: false, maxUses: null, useCount: 0, tokenPrefix: 'prefix', tokenGeneration: 1, revokedAt: null, createdAt: $time, createdBy: null);
    $activity = new ActivityEntrySnapshot(id: '1', verb: 'node.created', driveId: $node->driveId, targetType: 'node', targetId: $node->id, actorType: null, actorId: null, context: [], ip: null, userAgent: null, requestId: null, createdAt: $time);
    $grantPayload = (new GrantResource($grant))->resolve($request);
    $sharePayload = (new ShareResource($share))->resolve($request);
    $activityPayload = (new ActivityResource($activity, 'node'))->resolve($request);

    expect($nodePayload['created_at'])->toBeString()
        ->and($nodePayload['updated_at'])->toBeString()
        ->and($nodePayload['trashed_at'])->toBeNull()
        ->and($nodePayload['content_modified_at'])->toBeNull()
        ->and($grantPayload['created_at'])->toBe($expected)
        ->and($grantPayload['expires_at'])->toBe($expected)
        ->and($sharePayload['created_at'])->toBe($expected)
        ->and($sharePayload['expires_at'])->toBe($expected)
        ->and($activityPayload['occurred_at'])->toBe($expected)
        ->and($activityPayload['node_id'])->toBe($node->id);
});
