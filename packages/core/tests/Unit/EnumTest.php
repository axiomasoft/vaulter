<?php

declare(strict_types=1);

use Vaulter\Documents\Enums\Collaboration\CollaborationDriverType;
use Vaulter\Documents\Enums\Documents\DocumentType;

it('exposes document type metadata from attributes', function (): void {
    expect(DocumentType::RichText->label())->toBe('Rich text')
        ->and(DocumentType::RichText->icon())->toBe('document-text')
        ->and(DocumentType::RichText->tiptapExtensions())->toContain('StarterKit')
        ->and(DocumentType::values())->toBe(['richtext', 'table', 'markdown', 'template']);
});

it('labels collaboration drivers', function (): void {
    expect(CollaborationDriverType::Hocuspocus->label())->toBe('Hocuspocus')
        ->and(CollaborationDriverType::cases())->toBe([CollaborationDriverType::Hocuspocus]);
});
