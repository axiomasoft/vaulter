<?php

declare(strict_types=1);

use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Tests\Access\AccessTestHelpers;

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

    $response = $this->get('api/vaulter/nodes/'.$node->id.'/export?format=markdown')->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/markdown')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="Quarterly report.md"')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->getContent())->toBe("file body\n");
});

it('defaults to html and sends an RFC 6266 filename* for a non-ASCII name', function (): void {
    [$node, $user] = exportedNode('Отчёт 100%');
    $this->actingAs($user);

    $response = $this->get('api/vaulter/nodes/'.$node->id.'/export')->assertOk();
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

    $response = $this->getJson('api/vaulter/nodes/'.$node->id.'/export?format=html')->assertOk();

    expect($response->headers->get('Content-Type'))->not->toContain('json')
        ->and(DocumentRevision::query()->count())->toBe($revisions);
});
