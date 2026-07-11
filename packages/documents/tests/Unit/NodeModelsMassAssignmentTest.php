<?php

declare(strict_types=1);

use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;

/**
 * Mass-assignment hardening (P1.4 / B13): NodeComment/NodeDocumentVersion used to
 * carry `$guarded = []` (F-P1.8-02). author_id/node_id are morph/FK identity columns
 * and status/version_no are lifecycle bookkeeping — none of them may be set through
 * a future `fill($request->all())`.
 *
 * Red-before: prior to this item both models declared `protected $guarded = [];`,
 * so `fill()` accepted every key below.
 */
it('never mass-assigns author_id/node_id on NodeComment via fill() (F-P1.8-02)', function (): void {
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

it('never mass-assigns node_id/status on NodeDocumentVersion via fill() (F-P1.8-02)', function (): void {
    $version = new NodeDocumentVersion;
    $version->fill([
        'node_id' => 999,
        'status' => 'committed',
        'content' => ['type' => 'doc', 'content' => []],
    ]);

    expect($version->getAttributes())->not->toHaveKey('node_id')
        ->and($version->getAttributes())->not->toHaveKey('status')
        ->and($version->content)->toBe(['type' => 'doc', 'content' => []]);
});
