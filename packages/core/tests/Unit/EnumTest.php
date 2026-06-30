<?php

declare(strict_types=1);

use Vaulter\Enums\Access\PermissionLevel;
use Vaulter\Enums\Collaboration\CollaborationDriverType;
use Vaulter\Enums\Documents\DocumentType;

it('exposes document type metadata from attributes', function (): void {
    expect(DocumentType::RichText->label())->toBe('Rich text')
        ->and(DocumentType::RichText->icon())->toBe('document-text')
        ->and(DocumentType::RichText->tiptapExtensions())->toContain('StarterKit')
        ->and(DocumentType::values())->toBe(['richtext', 'table', 'markdown', 'template']);
});

it('orders permission levels', function (): void {
    expect(PermissionLevel::Manage->grants(PermissionLevel::View))->toBeTrue()
        ->and(PermissionLevel::Edit->grants(PermissionLevel::Edit))->toBeTrue()
        ->and(PermissionLevel::View->grants(PermissionLevel::Edit))->toBeFalse()
        ->and(PermissionLevel::Edit->rank())->toBe(3)
        ->and(PermissionLevel::Comment->label())->toBe('Can comment');
});

it('labels collaboration drivers', function (): void {
    expect(CollaborationDriverType::Reverb->label())->toBe('Laravel Reverb')
        ->and(CollaborationDriverType::Hocuspocus->label())->toBe('Hocuspocus');
});
