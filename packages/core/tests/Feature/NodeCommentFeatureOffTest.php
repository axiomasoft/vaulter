<?php

declare(strict_types=1);

/**
 * The comment routes belong to vaulter-documents. Core alone, without that package, serves none of
 * them: every verb answers the versioned 404 route_not_found, so a host that did not install the
 * package sees no new surface.
 */
it('serves no node-comment route without the documents package', function (): void {
    $this->getJson('api/vaulter/v1/nodes/1/comments')->assertNotFound()->assertJsonPath('code', 'route_not_found');
    $this->postJson('api/vaulter/v1/nodes/1/comments', ['body' => 'x'])->assertNotFound();
    $this->postJson('api/vaulter/v1/nodes/1/comments/1/resolve')->assertNotFound();
    $this->deleteJson('api/vaulter/v1/nodes/1/comments/1')->assertNotFound();
});
