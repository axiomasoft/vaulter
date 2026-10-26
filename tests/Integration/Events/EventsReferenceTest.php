<?php

declare(strict_types=1);

use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Events\EventCatalog;
use Vaulter\Events\EventsReference;

/**
 * The reference pages carry a table generated from the EventSpec of each catalogued type; a page that drifted
 * from the code fails here and in vaulter:events:reference --check. The catalogue is the one the command reads:
 * the enums of every offered module, by module id.
 */
$pages = [
    'en' => dirname(__DIR__, 3).'/docs/reference/events.md',
    'ru' => dirname(__DIR__, 3).'/docs/ru/reference/events.md',
];

function eventsReference(): EventsReference
{
    $offered = static fn (): array => ['documents' => [DocumentEventType::class], 'drive' => [DriveEventType::class]];

    return new EventsReference(new EventCatalog($offered, $offered));
}

it('renders one row per event type with its module', function (): void {
    $table = eventsReference()->render();

    expect(substr_count($table, "\n| `"))->toBe(count(DriveEventType::cases()) + count(DocumentEventType::cases()))
        ->and($table)->toContain('| `share.rotated` | drive | share |')
        ->and($table)->toContain('| `document.updated` | documents | node |');
});

it('keeps the generated block of each reference page current', function (string $path): void {
    $page = file_get_contents($path);
    expect($page)->toContain(EventsReference::START, EventsReference::END);
    $start = strpos($page, EventsReference::START);
    $block = substr($page, $start, strpos($page, EventsReference::END) + strlen(EventsReference::END) - $start);

    expect($block)->toBe(eventsReference()->block());
})->with($pages);

it('fails the check when a page drifts', function (): void {
    $reference = eventsReference();
    $page = "intro\n".$reference->block()."\noutro";
    $stale = str_replace('A folder was created.', 'A folder was made.', $page);

    expect($reference->apply($page))->toBe($page)
        ->and($reference->apply($stale))->toBe($page)
        ->and($reference->apply('no markers'))->toBeNull();
});
