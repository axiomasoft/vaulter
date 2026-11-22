<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\Documents\Exceptions\CollaborationUnavailableException;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers as Docs;
use Vaulter\Documents\Tests\Fixtures\Collaboration\AcmeDriver;
use Vaulter\Documents\Tests\Fixtures\Collaboration\WithAcme;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Kernel;

uses(WithAcme::class);

beforeEach(function (): void {
    expect(app(Kernel::class)->reasons())->toBe([]);
});

it('issues through the documents handle with a typed principal and opaque verifier roundtrip', function (): void {
    $drive = Docs::drive();
    $node = Documents::in($drive)->create('Acme', type: 'acme/ot');
    AccessTestHelpers::grant($node, RoleRegistry::WRITER, subjectId: 5);
    expect(fn () => Documents::in($drive)->collaborationSession($node))->toThrow(MissingActorException::class);
    $session = Documents::in($drive->actingAs(AccessTestHelpers::user(5)))->collaborationSession($node);
    $grant = app(AcmeDriver::class)->verify($session->token);
    expect($session->mode)->toBe(CollaborationMode::Write)->and($session->generation)->toBe(1)
        ->and($grant->principal)->toEqual(Docs::context($drive, 5)->actor->ref())
        ->and($grant->nodeId)->toBe($node->id)->and($grant->driveId)->toBe($node->driveId)
        ->and($grant->tenant)->toBeNull()->and($grant->stateFormat)->toBe('acme-ot-v1')
        ->and($session->expiresAt)->toBeLessThanOrEqual($grant->notAfter)
        ->and(fn () => app(AcmeDriver::class)->verify('tampered'))->toThrow(InvalidArgumentException::class);
});

it('retains generation on read and fences restored sessions with a fresh generation', function (): void {
    $drive = Docs::drive();
    $node = Documents::in($drive)->create('Acme', type: 'acme/ot');
    AccessTestHelpers::grant($node, RoleRegistry::OWNER, subjectId: 5);
    $documents = Documents::in($drive->actingAs(AccessTestHelpers::user(5)));
    $before = $documents->collaborationSession($node);
    $documents->restore($node, $documents->revisions($node)->items[0]->id);
    $after = $documents->collaborationSession($node);
    expect($after->generation)->toBe($before->generation + 1)->and($after->schemaVersion)->toBe(1);
    $drive->archive();
    expect($documents->collaborationSession($node)->mode)->toBe(CollaborationMode::Read);
});

it('keeps unknown adapters unavailable only after visibility authorization', function (): void {
    $drive = Docs::drive();
    $node = Documents::in($drive)->create('No adapter');
    $documents = Documents::in($drive->actingAs(AccessTestHelpers::user(5)));
    expect(fn () => $documents->collaborationSession($node))->toThrow(NodeNotFoundException::class);
    AccessTestHelpers::grant($node, RoleRegistry::READER, subjectId: 5);
    expect(fn () => $documents->collaborationSession($node))->toThrow(CollaborationUnavailableException::class);
});

it('hides a document in another drive or tenant and after trash', function (string $case): void {
    $drive = Docs::drive();
    $node = Documents::in($drive)->create('Acme', type: 'acme/ot');
    AccessTestHelpers::grant($node, RoleRegistry::OWNER, subjectId: 5);
    $acting = $drive;
    if ($case === 'drive') {
        $acting = Docs::drive();
    }
    if ($case === 'tenant') {
        Node::query()->whereKey($node->id)->update(['tenant_id' => '0']);
    }
    if ($case === 'trash') {
        $drive->nodes()->trash($node);
    }
    expect(fn () => Documents::in($acting->actingAs(AccessTestHelpers::user(5)))->collaborationSession($node))->toThrow(NodeNotFoundException::class);
})->with(['drive', 'tenant', 'trash']);

it('cannot issue a writable session from an open transaction', function (): void {
    $drive = Docs::drive();
    $node = Documents::in($drive)->create('Acme', type: 'acme/ot');
    AccessTestHelpers::grant($node, RoleRegistry::WRITER, subjectId: 5);
    $documents = Documents::in($drive->actingAs(AccessTestHelpers::user(5)));

    expect(fn () => app(VaulterDatabase::class)->transaction(fn () => $documents->collaborationSession($node)))
        ->toThrow(LogicException::class, 'requires committed document state');
    expect($documents->content($node)->stateVersion)->toBe(1)
        ->and($documents->collaborationSession($node)->generation)->toBe(1);
});
