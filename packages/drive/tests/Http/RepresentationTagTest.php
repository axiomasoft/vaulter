<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Http\Resources\Drive\NodeResource;
use Vaulter\Http\Support\RepresentationTag;

/**
 * The node tag hashes the caller's whole representation: key order does not matter,
 * anything the caller receives does.
 */
uses(RefreshDatabase::class);

it('ignores key order at every depth', function (): void {
    $a = ['id' => 'n', 'abilities' => ['view' => true, 'download' => false], 'properties' => ['b' => ['y' => '1', 'x' => '2']]];
    $b = ['properties' => ['b' => ['x' => '2', 'y' => '1']], 'abilities' => ['download' => false, 'view' => true], 'id' => 'n'];

    expect(RepresentationTag::node($a, 'n', 3))->toBe(RepresentationTag::node($b, 'n', 3))
        ->toMatch('/^"n\.3\.[0-9a-f]{16}"$/');
});

it('changes when an embedded ability changes although the sequence does not', function (): void {
    $base = ['id' => 'n', 'abilities' => ['view' => true, 'download' => true]];
    $without = ['id' => 'n', 'abilities' => ['view' => true, 'download' => false]];

    expect(RepresentationTag::node($base, 'n', 7))->not->toBe(RepresentationTag::node($without, 'n', 7));
});

it('keeps an empty object and an empty list apart', function (): void {
    expect(RepresentationTag::canonical(['properties' => new stdClass, 'list' => []]))->toBe('{"list":[],"properties":{}}')
        ->and(RepresentationTag::node(['p' => new stdClass], 'n', 1))->not->toBe(RepresentationTag::node(['p' => []], 'n', 1));
});

it('formats the document content tag from the content generation only', function (): void {
    expect(RepresentationTag::document('doc', 3, 12))->toBe('"doc.3.12"');
});

it('serializes empty node properties as {} and tags the node per caller', function (): void {
    $drive = V1::drive(userId: 1, role: RoleRegistry::OWNER);
    $folder = V1::folder($drive, 'tagged');
    AccessTestHelpers::grant(DriveTestHelpers::model($folder), RoleRegistry::READER, subjectId: 2);

    $owner = $drive->actingAs(V1::user(1))->nodes();
    $reader = $drive->actingAs(V1::user(2))->nodes();
    $asOwner = new NodeResource($owner->find($folder->id), $owner->abilities($folder->id));
    $asReader = new NodeResource($reader->find($folder->id), $reader->abilities($folder->id));

    expect(json_encode($asOwner->toArray(new Request)))->toContain('"properties":{}')
        ->and($asOwner->etag())->toBe($asOwner->toArray(new Request)['etag'])
        ->and($asOwner->etag())->not->toBe($asReader->etag())
        ->and(substr($asOwner->etag(), 0, strrpos($asOwner->etag(), '.')))->toBe(substr($asReader->etag(), 0, strrpos($asReader->etag(), '.')));
});
