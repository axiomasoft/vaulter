<?php

declare(strict_types=1);

use Vaulter\Enums\Events\EventType;
use Vaulter\Events\EventsReference;

/**
 * The reference pages carry a table generated from the EventSpec of each type; a page
 * that drifted from the code fails here and in vaulter:events:reference --check.
 */
$pages = [
    'en' => dirname(__DIR__, 4).'/docs/reference/events.md',
    'ru' => dirname(__DIR__, 4).'/docs/ru/reference/events.md',
];

it('renders one row per event type', function (): void {
    $table = EventsReference::render();

    expect(substr_count($table, "\n| `"))->toBe(count(EventType::cases()))
        ->and($table)->toContain('| `share.rotated` | share |')
        ->and($table)->toContain('| `document.updated` | node |');
});

it('keeps the generated block of each reference page current', function (string $path): void {
    $page = file_get_contents($path);
    expect($page)->toContain(EventsReference::START, EventsReference::END);
    $start = strpos($page, EventsReference::START);
    $block = substr($page, $start, strpos($page, EventsReference::END) + strlen(EventsReference::END) - $start);

    expect($block)->toBe(EventsReference::block());
})->with($pages);

it('fails the check when a page drifts', function (): void {
    $page = "intro\n".EventsReference::block()."\noutro";
    $stale = str_replace('A folder was created.', 'A folder was made.', $page);

    expect(EventsReference::apply($page))->toBe($page)
        ->and(EventsReference::apply($stale))->toBe($page)
        ->and(EventsReference::apply('no markers'))->toBeNull();
});
