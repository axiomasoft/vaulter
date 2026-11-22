<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\ChangeDocumentWriteModeAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Enums\Documents\DocumentWriteMode;
use Vaulter\Documents\Tests\Concurrency\RaceFixtures;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Fixtures\Collaboration\WithAcme;
use Vaulter\Documents\Tests\Fixtures\GrantedDocuments;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Http\Support\RepresentationTag;
use Vaulter\Testing\Concurrency\ForkRace;

uses(WithAcme::class);

beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this, app(VaulterDatabase::class)->connection()->getName());
    GrantedDocuments::configure();
});

function waitForWriteModeRace(Closure $condition): void
{
    $deadline = hrtime(true) + 8_000_000_000;
    while (!$condition()) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the document writer to block.');
        }
        usleep(2_000);
    }
}

it('serializes competing Laravel writers with the same If-Match after :dataset', function (string $outcome): void {
    $initial = ['columns' => ['initial']];
    $node = GrantedDocuments::document(level: RoleRegistry::OWNER, content: OpaqueContent::fromArray($initial), type: 'acme/ot');
    $tag = RepresentationTag::document((string) $node->getKey(), 0, 1);
    $context = DocumentTestHelpers::context($node, 1);
    $update = static fn (string $text) => app(UpdateDocumentContentAction::class)->execute($context, new UpdateDocumentContentInput(
        node: $node, content: ['columns' => [$text]], matchesTag: static fn (string $current): bool => $tag === $current,
    ));
    $switch = static fn () => app(ChangeDocumentWriteModeAction::class)->execute($context, $node, expectedGeneration: 0, expectedPosition: 1);
    $dir = sys_get_temp_dir().'/vlt_mode_'.getmypid().'_'.bin2hex(random_bytes(4));
    mkdir($dir);

    try {
        $results = RaceFixtures::race([
            static fn (): array => RaceFixtures::attempt(static function () use ($outcome, $dir, $update, $switch): void {
                $rollback = new RuntimeException('Rollback first write');
                $db = app(VaulterDatabase::class);
                try {
                    $db->transaction(static function () use ($db, $outcome, $dir, $update, $switch, $rollback): void {
                        $outcome === 'switch' || $outcome === 'switch twice' ? $switch() : $update('first');
                        touch($dir.'/holding');
                        waitForWriteModeRace(static fn (): bool => is_file($dir.'/waiting'));
                        $pid = (int) file_get_contents($dir.'/waiting');
                        waitForWriteModeRace(static fn (): bool => (bool) $db->connection()->scalar(
                            'select pg_backend_pid() = any(pg_blocking_pids(?))', [$pid],
                        ));
                        if ($outcome === 'rollback') {
                            throw $rollback;
                        }
                    });
                } catch (RuntimeException $caught) {
                    if ($caught !== $rollback) {
                        throw $caught;
                    }
                }
            }),
            static fn (): array => RaceFixtures::attempt(static function () use ($dir, $outcome, $update, $switch): void {
                waitForWriteModeRace(static fn (): bool => is_file($dir.'/holding'));
                $pid = app(VaulterDatabase::class)->connection()->scalar('select pg_backend_pid()');
                file_put_contents($dir.'/pid', (string) $pid);
                rename($dir.'/pid', $dir.'/waiting');
                if ($outcome === 'switch twice') {
                    expect($switch()->grant->generation)->toBe(1);

                    return;
                }
                $update('second');
            }),
        ]);
    } finally {
        array_map(unlink(...), glob($dir.'/*') ?: []);
        rmdir($dir);
    }

    $document = GrantedDocuments::aggregate($node);
    $switched = str_starts_with($outcome, 'switch');
    expect($results[0]['kind'])->toBe('committed')
        ->and($results[1]['kind'])->toBe(match ($outcome) {
            'switch' => 'document_command_required',
            'commit' => 'document_state_mismatch',
            default => 'committed',
        })
        ->and($results[1]['status'])->toBe(match ($outcome) {
            'switch' => 409,
            'commit' => 412,
            default => null,
        })
        ->and($document->state_version)->toBe(2)
        ->and($document->write_mode)->toBe($switched ? DocumentWriteMode::Journal : DocumentWriteMode::Snapshot)
        ->and($document->collab_epoch)->toBe((int) $switched)
        ->and($document->payload?->content)->toBe($switched ? $initial : ['columns' => [$outcome === 'commit' ? 'first' : 'second']]);
})->with(['commit', 'rollback', 'switch', 'switch twice']);
