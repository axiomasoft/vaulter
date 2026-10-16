<?php

declare(strict_types=1);

use Vaulter\Attributes\EventSpec;
use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Events\EventCatalog;
use Vaulter\Events\EventTypeContract;
use Vaulter\Testing\Events\EventConformance;

/**
 * The event catalogue (D38): the modules contribute their enums, every case declares its EventSpec, and the
 * values stay the stored identities. Catalogues built by hand, without an application.
 */
it('gives every catalogued type a complete EventSpec', function (): void {
    $types = [...DriveEventType::cases(), ...DocumentEventType::cases()];
    expect($types)->toHaveCount(32);
    foreach ($types as $type) {
        $attributes = (new ReflectionEnumUnitCase($type::class, $type->name))->getAttributes(EventSpec::class);
        expect($attributes)->toHaveCount(1, (string) $type->value);
        $spec = $type->spec();
        expect($spec->subject)->toBeIn(['drive', 'node', 'comment', 'share', 'upload'])
            ->and($spec->description)->not->toBe('')
            ->and($spec->journal)->toBeIn([null, 'upsert', 'remove'])
            ->and($spec->activity || $spec->external)->toBeTrue();
        foreach ($spec->data as $key => $kind) {
            expect($kind)->toBeIn(['string', 'string|null', 'int', 'bool', 'list<string>', 'object']);
            expect(rtrim($key, '?'))->toMatch('/^[a-z][a-z0-9_]*$/');
        }
    }
});

it('takes each type from the module that owns it', function (): void {
    $catalog = new EventCatalog(
        static fn (): array => ['documents' => [DocumentEventType::class], 'drive' => [DriveEventType::class]],
        static fn (): array => [],
    );

    expect($catalog->get('node.moved'))->toBe(DriveEventType::NodeMoved)
        ->and($catalog->owner('node.moved')->value)->toBe('drive')
        ->and($catalog->get('comment.added'))->toBe(DocumentEventType::CommentAdded)
        ->and($catalog->owner('comment.added')->value)->toBe('documents')
        ->and($catalog->has('no.such'))->toBeFalse()
        ->and($catalog->resolve('no.such'))->toBeNull()
        ->and(fn () => $catalog->get('no.such'))->toThrow(InvalidArgumentException::class);
});

it('derives the external types from the specs alone', function (): void {
    $catalog = new EventCatalog(
        static fn (): array => ['documents' => [DocumentEventType::class], 'drive' => [DriveEventType::class]],
        static fn (): array => [],
    );
    $activity = array_map(static fn (EventTypeContract $type): string => (string) $type->value, array_filter($catalog->all(), static fn (EventTypeContract $type): bool => $type->spec()->activity));

    expect($activity)->not->toContain('document.updated')
        ->and($catalog->externalValues())->not->toContain('upload.initiated', 'upload.aborted', 'upload.expired')
        ->and($catalog->externalValues())->toContain('document.updated', 'share.updated', 'share.rotated', 'node.updated', 'access.inheritance_changed')
        ->and(DriveEventType::NodeTrashed->spec()->journal)->toBe('remove')
        ->and(DriveEventType::NodeMoved->spec()->journal)->toBe('upsert')
        ->and(DriveEventType::ShareRotated->spec()->journal)->toBeNull();
});

it('refuses a value declared by two modules in a catalogue built by hand', function (): void {
    $catalog = new EventCatalog(
        static fn (): array => ['drive' => [DriveEventType::class], 'other' => [DriveEventType::class]],
        static fn (): array => [],
    );

    expect(fn () => $catalog->all())->toThrow(InvalidArgumentException::class, 'declared by both the module "drive" and the module "other"');
});

it('resolves the stored values of an installed module that is switched off', function (): void {
    $catalog = new EventCatalog(
        static fn (): array => ['drive' => [DriveEventType::class]],
        static fn (): array => ['documents' => [DocumentEventType::class], 'drive' => [DriveEventType::class]],
    );

    expect($catalog->has('comment.added'))->toBeFalse()
        ->and($catalog->resolve('comment.added'))->toBe(DocumentEventType::CommentAdded)
        ->and($catalog->resolve('node.moved'))->toBe(DriveEventType::NodeMoved);
});

it('keeps forbidden keys out of every spec', function (): void {
    foreach ([...DriveEventType::cases(), ...DocumentEventType::cases()] as $type) {
        expect(array_intersect($type->spec()->allowedKeys(), EventConformance::FORBIDDEN))->toBe([], (string) $type->value);
    }
});
