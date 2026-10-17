<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Tests\Collaboration\HubTestHelpers as Hub;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

/** An export is a download: an attachment that is never cached, sniffed or run in the API origin. */
beforeEach(function (): void {
    Hub::configure();
    $this->node = Hub::document(level: RoleRegistry::READER, actorId: 1);
    $this->id = (string) $this->node->getKey();
    $this->actingAs(AccessTestHelpers::user(1));
});

it('sends the exporter bytes as an attachment with the egress headers', function (): void {
    $response = $this->get(Docs::url('documents/'.$this->id.'/export/markdown'))->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/markdown')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename=Spec.md')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("sandbox; default-src 'none'")
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('Accept-Ranges'))->toBe('none')
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen((string) $response->getContent()))
        ->and($response->getContent())->toBe("Draft\n");
});

it('answers an unknown format 422 unknown_export_format with the allowed ones', function (): void {
    $this->getJson(Docs::url('documents/'.$this->id.'/export/docx'))
        ->assertStatus(422)
        ->assertJsonPath('code', 'unknown_export_format')
        ->assertJsonPath('meta.allowed', ['html', 'markdown']);
});

it('exports the checkpoint named by ?revision= instead of the working state', function (): void {
    $writer = Hub::document(level: RoleRegistry::WRITER, actorId: 1);
    $id = (string) $writer->getKey();
    $first = $this->getJson(Docs::url('documents/'.$id.'/revisions'))->json('data.0.id');
    Docs::put($id, Docs::doc('Newer'))->assertOk();

    expect($this->get(Docs::url('documents/'.$id.'/export/markdown'))->getContent())->toBe("Newer\n")
        ->and($this->get(Docs::url('documents/'.$id.'/export/markdown?revision='.$first))->getContent())->toBe("Draft\n");
    $this->getJson(Docs::url('documents/'.$id.'/export/markdown?revision=999999'))->assertNotFound()->assertJsonPath('code', 'document_revision_not_found');
});

it('refuses a previewer, who may view but not download, and hides the document from a stranger', function (): void {
    $node = Hub::document(level: RoleRegistry::PREVIEWER, actorId: 4);
    $this->actingAs(AccessTestHelpers::user(4));
    $this->getJson(Docs::url('documents/'.$node->getKey().'/export/html'))->assertForbidden()->assertJsonPath('code', 'permission_denied');

    $this->actingAs(AccessTestHelpers::user(9));
    $this->getJson(Docs::url('documents/'.$this->id.'/export/html'))->assertNotFound()->assertJsonPath('code', 'node_not_found');
});

it('marks an export of partly readable content with X-Vaulter-Export-Lossy', function (): void {
    $writer = Hub::document(level: RoleRegistry::READER, actorId: 1);
    app(VaulterDatabase::class)->write(fn () => Hub::aggregate($writer)->forceFill(['content' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'kept']]],
        ['type' => 'hostWidgetNode'],
    ]]])->save());

    $this->get(Docs::url('documents/'.$writer->getKey().'/export/html'))->assertOk()->assertHeader('X-Vaulter-Export-Lossy', '1');
    expect($this->get(Docs::url('documents/'.$this->id.'/export/html'))->assertOk()->headers->has('X-Vaulter-Export-Lossy'))->toBeFalse();
});
