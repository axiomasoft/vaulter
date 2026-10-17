<?php

declare(strict_types=1);

use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Exceptions\InvalidDriveStateException;
use Vaulter\Drive\Exceptions\Nodes\NodeTrashedByAncestorException;
use Vaulter\Drive\Exceptions\Nodes\RestoreDestinationRequiredException;
use Vaulter\Drive\Exceptions\Nodes\TreeTraversalIncompleteException;
use Vaulter\Drive\Models\Node;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Testing\Support\ProblemProbeRoutes;

/**
 * PLAN2.D37 error contract: stable codes and statuses, an actionable message, and the
 * trash root in the response of a member restore.
 */
dataset('deletion exceptions', [
    'node_trashed_by_ancestor' => [fn () => NodeTrashedByAncestorException::forNode((new Node)->forceFill(['id' => 5]), 3), 'node_trashed_by_ancestor', 409],
    'restore_destination_required' => [fn () => RestoreDestinationRequiredException::forNode((new Node)->forceFill(['id' => 5])), 'restore_destination_required', 409],
    'tree_traversal_incomplete' => [fn () => TreeTraversalIncompleteException::forBound(101), 'tree_traversal_incomplete', 409],
    'drive_archived' => [fn () => DriveArchivedException::forDrive(7), 'drive_archived', 409],
    'invalid_drive_state' => [fn () => InvalidDriveStateException::for(7, 'archive', DriveStatus::Archived), 'invalid_drive_state', 409],
]);

it('carries a stable problem code, status and an actionable message', function (Closure $make, string $code, int $status): void {
    $exception = $make();

    expect($exception)->toBeInstanceOf(ProblemException::class)
        ->and($exception->problemCode())->toBe($code)
        ->and($exception->httpStatus())->toBe($status)
        ->and($exception->getMessage())->toStartWith('[Vaulter]')->toContain('Why:')->toContain('Fix:')->toContain('Docs:')
        ->and($exception->getMessage())->toContain('errors.md#'.str_replace('_', '-', $code));
})->with('deletion exceptions');

it('answers a member restore with the trash root in the response context', function (): void {
    ProblemProbeRoutes::register(['case' => NodeTrashedByAncestorException::forNode((new Node)->forceFill(['id' => 5]), 3)]);

    $this->getJson(ProblemProbeRoutes::path('case'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'node_trashed_by_ancestor')
        ->assertJsonPath('meta.context.trash_root_id', '3');
});
