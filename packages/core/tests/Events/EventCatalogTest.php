<?php

declare(strict_types=1);

use Vaulter\Attributes\EventSpec;
use Vaulter\Enums\Events\EventType;
use Vaulter\Tests\Events\EventConformance;

/**
 * The closed catalogue: every type declares its EventSpec, and the sink matrix the
 * recorder reads is exactly that declaration.
 */
it('gives every EventType case a complete EventSpec', function (): void {
    foreach (EventType::cases() as $type) {
        $attributes = (new ReflectionEnumUnitCase(EventType::class, $type->name))->getAttributes(EventSpec::class);
        expect($attributes)->toHaveCount(1, $type->value);
        $spec = $type->spec();
        expect($spec->subject)->toBeIn(['drive', 'node', 'comment', 'share', 'upload'])
            ->and($spec->description)->not->toBe('')
            ->and($spec->journal)->toBeIn([null, 'upsert', 'remove'])
            ->and($spec->activity || $spec->webhook)->toBeTrue();
        foreach ($spec->data as $key => $kind) {
            expect($kind)->toBeIn(['string', 'string|null', 'int', 'bool', 'list<string>', 'object']);
            expect(rtrim($key, '?'))->toMatch('/^[a-z][a-z0-9_]*$/');
        }
    }
});

it('derives the sink matrix from the specs alone', function (): void {
    $activity = array_map(static fn (EventType $type): string => $type->value, EventType::activityTypes());
    $webhook = array_map(static fn (EventType $type): string => $type->value, EventType::webhookTypes());

    expect($activity)->not->toContain('document.updated')
        ->and($webhook)->not->toContain('upload.initiated', 'upload.aborted', 'upload.expired')
        ->and($webhook)->toContain('document.updated', 'share.updated', 'share.rotated', 'node.updated', 'access.inheritance_changed')
        ->and(EventType::names())->toBe($webhook)
        ->and(EventType::NodeTrashed->journalOperation())->toBe('remove')
        ->and(EventType::NodeMoved->journalOperation())->toBe('upsert')
        ->and(EventType::ShareRotated->journalOperation())->toBeNull();
});

it('keeps forbidden keys out of every spec', function (): void {
    foreach (EventType::cases() as $type) {
        expect(array_intersect($type->spec()->allowedKeys(), EventConformance::FORBIDDEN))->toBe([], $type->value);
    }
});
