<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

function exportedNode(string $name, string $text = 'file body'): array
{
    $handle = DocumentTestHelpers::drive();
    $node = Documents::in($handle)->create($name, content: DocumentTestHelpers::content($text)->toArray());
    AccessTestHelpers::grant($node, RoleRegistry::READER, subjectId: 7);

    return [$node, AccessTestHelpers::user(7)];
}

it('answers the exporter bytes as an attachment that is never cached or sniffed', function (): void {
    [$node, $user] = exportedNode('Quarterly report');
    $this->actingAs($user);

    $response = $this->get('api/vaulter/v1/documents/'.$node->id.'/export/markdown')->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/markdown')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="Quarterly report.md"')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("sandbox; default-src 'none'")
        ->and($response->headers->get('X-Vaulter-Api-Version'))->toBe('1')
        ->and($response->getContent())->toBe("file body\n");
});

it('sends an RFC 6266 filename* for a non-ASCII name', function (): void {
    [$node, $user] = exportedNode('Отчёт 100%');
    $this->actingAs($user);

    $response = $this->get('api/vaulter/v1/documents/'.$node->id.'/export/html')->assertOk();
    $disposition = (string) $response->headers->get('Content-Disposition');

    expect($response->headers->get('Content-Type'))->toStartWith('text/html')
        ->and($disposition)->toStartWith('attachment; filename=')
        ->and($disposition)->toContain("filename*=utf-8''".rawurlencode('Отчёт 100%.html'))
        ->and($response->getContent())->toContain('file body');
});

it('does not answer JSON and creates no revision', function (): void {
    [$node, $user] = exportedNode('Plain');
    $this->actingAs($user);
    $revisions = DocumentRevision::query()->count();

    $response = $this->getJson('api/vaulter/v1/documents/'.$node->id.'/export/html')->assertOk();

    expect($response->headers->get('Content-Type'))->not->toContain('json')
        ->and(DocumentRevision::query()->count())->toBe($revisions);
});

it('marks an export of partly readable content with X-Vaulter-Export-Lossy', function (): void {
    [$node, $user] = exportedNode('Partly readable');
    app(VaulterDatabase::class)->write(fn () => DocumentTestHelpers::document(DriveTestHelpers::model($node))->payload?->forceFill(['content' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'kept']]],
        ['type' => 'hostWidgetNode'],
    ]]])->save());
    $this->actingAs($user);

    $this->get('api/vaulter/v1/documents/'.$node->id.'/export/html')->assertOk()->assertHeader('X-Vaulter-Export-Lossy', '1');

    [$whole, $reader] = exportedNode('Whole');
    $this->actingAs($reader);
    expect($this->get('api/vaulter/v1/documents/'.$whole->id.'/export/html')->assertOk()->headers->has('X-Vaulter-Export-Lossy'))->toBeFalse();
});
