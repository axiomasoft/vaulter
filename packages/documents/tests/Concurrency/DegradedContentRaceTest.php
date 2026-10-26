<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Content\ContentHasher;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Data\Revisions\RestoreDocumentRevisionInput;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Concurrency\RaceFixtures;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Testing\Concurrency\ForkRace;

/**
 * A write without an expectation passes the caller's writability check, and a restore of
 * a newer-schema or lossy revision then commits before the write takes its locks. The
 * write must be refused against the locked document and leave nothing behind: no
 * content, Yjs state, schema version, checkpoint or event. The order is forced with
 * marker files, so the refusal is required, not one of two acceptable outcomes.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this, app(VaulterDatabase::class)->connection()->getName());
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
});

function degradedRaceWait(string $marker): void
{
    $deadline = hrtime(true) + 8_000_000_000;
    while (!is_file($marker) && hrtime(true) < $deadline) {
        usleep(500);
    }
}

dataset('degraded revisions', [
    'newer schema' => ['newer-schema'],
    'content the type cannot read losslessly' => ['lossy'],
]);

it('refuses an update whose precheck went stale to a restore that committed before its locks: :dataset', function (string $case): void {
    $node = RaceFixtures::document();
    $update = static fn (string $text) => app(UpdateDocumentContentAction::class)->execute(
        DocumentTestHelpers::context($node), new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content($text)),
    );
    $update('degraded');
    $degraded = DocumentTestHelpers::head($node);
    $stored = $case === 'lossy'
        ? ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'before']]], ['type' => 'acmeWidget']]]
        : DocumentTestHelpers::content('degraded')->toArray();
    // Written by a newer release of the type, or by an editor extension this installation has off.
    app(VaulterDatabase::class)->write(fn () => DocumentRevision::query()->whereKey($degraded->getKey())->update(
        $case === 'lossy' ? ['content' => json_encode($stored)] : ['schema_version' => 99],
    ));
    $update('current');
    $before = DocumentTestHelpers::document($node);
    $dir = sys_get_temp_dir().'/vlt_degraded_'.getmypid().'_'.bin2hex(random_bytes(4));
    mkdir($dir);

    try {
        $results = RaceFixtures::race([
            // The caller's check sees a writable document; the restore lands before the action's first statement.
            static function () use ($node, $update, $dir): array {
                return RaceFixtures::attempt(static function () use ($node, $update, $dir): void {
                    app(ContentDecoder::class)->editableType(DocumentTestHelpers::document($node));
                    $armed = true;
                    app(VaulterDatabase::class)->connection()->beforeExecuting(static function () use (&$armed, $dir): void {
                        if (!$armed) {
                            return;
                        }
                        $armed = false;
                        touch($dir.'/prechecked');
                        degradedRaceWait($dir.'/restored');
                    });
                    $update('late edit');
                });
            },
            static function () use ($node, $degraded, $dir): array {
                degradedRaceWait($dir.'/prechecked');
                $outcome = RaceFixtures::attempt(static fn () => app(RestoreDocumentRevisionAction::class)->execute(
                    DocumentTestHelpers::context($node), new RestoreDocumentRevisionInput(node: $node, revision: $degraded),
                ));
                touch($dir.'/restored');

                return $outcome;
            },
        ]);
    } finally {
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }

    $after = DocumentTestHelpers::document($node);
    expect($results[1]['kind'])->toBe('committed')
        ->and($results[0]['kind'])->toBe('document_content_degraded')
        ->and($results[0]['status'])->toBe(409)
        ->and($results[0]['events'])->toBe(0)
        // Only the restore wrote: one state version, one epoch, one checkpoint more.
        ->and($after->state_version)->toBe($before->state_version + 1)
        ->and($after->collab_epoch)->toBe($before->collab_epoch + 1)
        ->and($after->revision_count)->toBe($before->revision_count + 1)
        ->and($after->schema_version)->toBe($case === 'lossy' ? 1 : 99)
        // jsonb returns object keys in its own order; the canonical hash ignores it.
        ->and(ContentHasher::hash($after->content))->toBe(ContentHasher::hash($stored))
        ->and($after->collab_state)->toBeNull();
})->with('degraded revisions');
