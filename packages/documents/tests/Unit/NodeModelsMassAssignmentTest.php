<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\MassAssignmentException;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Revisions\DocumentRevision;

/**
 * Mass-assignment hardening: NodeComment/DocumentRevision used to
 * carry `$guarded = []`. author_id/node_id are morph/FK identity columns
 * and status/version_no are lifecycle bookkeeping — none of them may be set through
 * a future `fill($request->all())`.
 *
 * Red-before: prior to this item both models declared `protected $guarded = [];`,
 * so `fill()` accepted every key below.
 */
it('never mass-assigns author_id/node_id on NodeComment via fill()', function (): void {
    $comment = new NodeComment;
    $comment->fill([
        'author_id' => 999,
        'node_id' => 999,
        'body' => 'legit comment text',
    ]);

    expect($comment->getAttributes())->not->toHaveKey('author_id')
        ->and($comment->getAttributes())->not->toHaveKey('node_id')
        ->and($comment->body)->toBe('legit comment text');
});

it('never mass-assigns anything on a DocumentRevision checkpoint via fill()', function (): void {
    // Checkpoints are written only by DocumentStateWriter with forceFill(); fill()
    // with request-shaped input must not reach a single column.
    expect(fn () => (new DocumentRevision)->fill([
        'document_id' => 999,
        'status' => 'committed',
        'content' => ['type' => 'doc', 'content' => []],
    ]))->toThrow(MassAssignmentException::class);
});
