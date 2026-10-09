<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The event pipeline has one entrance: Actions hand events to EventRecorder, which
 * stores them and dispatches them after the commit. A direct dispatch or an
 * after-commit marker on an event would bypass the activity and outbox rows.
 */
// Each package is its own PSR-4 root, so each namespace is named.
arch('no Vaulter class defers its own dispatch past the commit')
    ->expect(['Vaulter', 'Vaulter\Drive', 'Vaulter\Documents', 'Vaulter\Media'])
    ->not->toImplement(ShouldDispatchAfterCommit::class);

/** @return list<string> package src files, comments aside, that name ShouldDispatchAfterCommit */
function eventPipelineAfterCommitMarkers(): array
{
    $root = dirname(__DIR__, 3);
    $offenders = [];
    foreach (glob($root.'/*/src', GLOB_ONLYDIR) ?: [] as $src) {
        $files = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)), '/\.php$/');
        foreach ($files as $file) {
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) && str_contains($token[1], 'ShouldDispatchAfterCommit')) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
    }

    return $offenders;
}

it('names ShouldDispatchAfterCommit nowhere in package code', function (): void {
    expect(eventPipelineAfterCommitMarkers())->toBe([]);
});

/** @return list<string> "path:line" of every direct dispatch in a package src tree, EventRecorder aside */
function eventPipelineDirectDispatches(): array
{
    $root = dirname(__DIR__, 3);
    $offenders = [];
    foreach (glob($root.'/*/src', GLOB_ONLYDIR) ?: [] as $src) {
        $files = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)), '/\.php$/');
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (str_ends_with($path, '/core/src/Events/EventRecorder.php')) {
                continue;
            }
            foreach (file($path) ?: [] as $number => $line) {
                if (preg_match('/\bevent\(\s*new\b|Event::dispatch\(/', $line) === 1) {
                    $offenders[] = substr($path, strlen($root) + 1).':'.($number + 1);
                }
            }
        }
    }

    return $offenders;
}

it('records events only through the EventRecorder', function (): void {
    expect(eventPipelineDirectDispatches())->toBe([]);
});

it('keeps the removed event subscribers removed', function (string $class): void {
    expect(class_exists($class))->toBeFalse();
})->with([
    'Vaulter\Webhooks\RecordsOutbox',
    'Vaulter\Activity\RecordsActivity',
    'Vaulter\Drive\Webhooks\RecordsDriveOutbox',
    'Vaulter\Drive\Activity\RecordsDriveActivity',
]);
