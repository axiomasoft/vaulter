<?php

declare(strict_types=1);

/**
 * ADR-0014 шаг 1 — the node-comment routes are gated on the 'comments' feature (default
 * OFF). Under the base TestCase (feature off) they are never registered, so every verb
 * 404s. This pins the additive opt-in: a host that does not enable 'comments' sees no
 * new surface.
 */
it('does not register the node-comment routes when the feature is off', function (): void {
    $this->getJson('api/vaulter/nodes/1/comments')->assertNotFound();
    $this->postJson('api/vaulter/nodes/1/comments', ['body' => 'x'])->assertNotFound();
    $this->postJson('api/vaulter/nodes/1/comments/1/resolve')->assertNotFound();
    $this->deleteJson('api/vaulter/nodes/1/comments/1')->assertNotFound();
});
