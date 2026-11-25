<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Drive\Tests\Http\DriveV1;

/**
 * File content over v1: single-shot upload, revisions (list, add, restore, retention,
 * delete) and strict-mode downloads of the head and of one exact revision.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    $this->drive = V1::drive(userId: 1);
    $this->driveId = (string) $this->drive->model()->getKey();
    $this->actingAs(V1::user(1));
});

function streamedContent(TestResponse $response): string
{
    return $response->streamedContent();
}

it('uploads a file in one request and answers 201 with the node and its tag', function (): void {
    $folder = V1::folder($this->drive, 'docs');

    $response = $this->post(V1::url('drives/'.$this->driveId.'/files'), [
        'parent_id' => $folder->id,
        'file' => UploadedFile::fake()->createWithContent('report.txt', 'hello drive'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'file')
        ->assertJsonPath('data.parent_id', $folder->id)
        ->assertJsonPath('data.size_bytes', 11)
        ->assertJsonPath('data.file.revision_number', 1);

    expect($response->headers->get('ETag'))->toBe($response->json('data.etag'))
        ->and($response->json('data'))->not->toHaveKeys(['file_id', 'storage_id', 'current_version_id']);
});

it('lists revisions newest first, adds one with If-Match and restores an older one', function (): void {
    $file = V1::file($this->drive, 'v.txt', 'one');

    $added = $this->post(V1::url('files/'.$file->id.'/revisions'), [
        'file' => UploadedFile::fake()->createWithContent('v.txt', 'two two'),
    ], ['Accept' => 'application/json', 'If-Match' => DriveV1::etag($this, $file->id)])
        ->assertCreated()
        ->assertJsonPath('data.number', 2)
        ->assertJsonPath('data.is_head', true)
        ->assertJsonPath('data.size_bytes', 7);
    expect(array_keys($added->json('data')))->toBe(['id', 'number', 'size_bytes', 'mime', 'declared_mime', 'sha256', 'scan_status', 'keep_forever', 'is_head', 'created_by', 'created_at']);

    $list = $this->getJson(V1::url('files/'.$file->id.'/revisions'))->assertOk()
        ->assertJsonPath('data.0.number', 2)
        ->assertJsonPath('data.1.number', 1)
        ->assertJsonPath('meta.next_cursor', null);
    $first = $list->json('data.1.id');

    $this->postJson(V1::url('files/'.$file->id.'/revisions/'.$first.'/restore'), [], ['If-Match' => DriveV1::etag($this, $file->id)])
        ->assertOk()
        ->assertJsonPath('data.number', 3)
        ->assertJsonPath('data.is_head', true);
    expect(streamedContent($this->get(V1::url('files/'.$file->id.'/download'))->assertOk()))->toBe('one');
});

it('pages revisions on the revision number alone, so the next page seeks the unique key', function (): void {
    $file = V1::file($this->drive, 'paged.txt', 'one');
    foreach (['two', 'three'] as $bytes) {
        $this->drive->files()->addRevision($file->id, UploadSource::fromStream(DriveTestHelpers::streamFor($bytes), 'paged.txt'));
    }
    $page = $this->getJson(V1::url('files/'.$file->id.'/revisions?per_page=2'))->assertOk()
        ->assertJsonPath('data.0.number', 3)->assertJsonPath('data.1.number', 2);

    $selects = [];
    DB::listen(static function (QueryExecuted $query) use (&$selects): void {
        if (str_contains($query->sql, 'file_revisions') && preg_match('/number[`"]? </', $query->sql) === 1) {
            $selects[] = $query->sql;
        }
    });
    $this->getJson(V1::url('files/'.$file->id.'/revisions?per_page=2&cursor='.urlencode($page->json('meta.next_cursor'))))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 1);

    expect($selects)->toHaveCount(1)
        ->and(strtolower($selects[0]))->not->toContain(' or ');
});

it('pins a revision, refuses to delete the head and deletes an old revision', function (): void {
    $file = V1::file($this->drive, 'r.txt', 'old');
    $this->drive->files()->addRevision($file->id, UploadSource::fromString('new', 'r.txt'));
    [$head, $old] = $this->getJson(V1::url('files/'.$file->id.'/revisions'))->json('data');

    $this->patchJson(V1::url('files/'.$file->id.'/revisions/'.$old['id']), ['keep_forever' => true])
        ->assertOk()->assertJsonPath('data.keep_forever', true);
    $this->deleteJson(V1::url('files/'.$file->id.'/revisions/'.$old['id']))->assertStatus(409)->assertJsonPath('code', 'revision_pinned');
    $this->patchJson(V1::url('files/'.$file->id.'/revisions/'.$old['id']), ['keep_forever' => false])->assertOk();

    $this->deleteJson(V1::url('files/'.$file->id.'/revisions/'.$head['id']))->assertStatus(409)->assertJsonPath('code', 'cannot_delete_head_revision');
    $this->deleteJson(V1::url('files/'.$file->id.'/revisions/'.$old['id']))->assertNoContent();
    $this->getJson(V1::url('files/'.$file->id.'/revisions'))->assertJsonCount(1, 'data');
});

it('downloads the head and one exact revision through the strict responder', function (): void {
    $file = V1::file($this->drive, 'отчёт.txt', 'first', 'text/plain');
    $first = (string) DriveTestHelpers::model($file)->file()->firstOrFail()->currentRevision()->firstOrFail()->getKey();
    $this->drive->files()->addRevision($file->id, UploadSource::fromString('second', 'отчёт.txt'));

    $head = $this->get(V1::url('files/'.$file->id.'/download'))->assertOk();
    expect(streamedContent($head))->toBe('second')
        ->and($head->headers->get('Content-Disposition'))->toStartWith('attachment; filename=')->toContain("filename*=utf-8''")
        ->and($head->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($head->headers->get('Content-Security-Policy'))->toBe("sandbox; default-src 'none'")
        ->and($head->headers->get('Cache-Control'))->toContain('private')->toContain('no-store')
        ->and($head->headers->get('X-Vaulter-Api-Version'))->toBe('1');

    expect(streamedContent($this->get(V1::url('files/'.$file->id.'/revisions/'.$first.'/download'))->assertOk()))->toBe('first');
});

it('answers a revision id the key cannot hold as node_not_found and does not query it', function (): void {
    $file = V1::file($this->drive, 'v.txt', 'bytes');
    $bad = 'no-such-revision';
    $bound = [];
    DB::listen(function (QueryExecuted $query) use (&$bound, $bad): void {
        foreach ($query->bindings as $binding) {
            if (is_scalar($binding) && (string) $binding === $bad) {
                $bound[] = $query->sql;
            }
        }
    });

    foreach (['strict', 'direct_signed'] as $mode) {
        config()->set('vaulter-drive.downloads.delivery_mode', $mode);
        $this->getJson(V1::url('files/'.$file->id.'/revisions/'.$bad.'/download'))
            ->assertNotFound()->assertJsonPath('code', 'node_not_found');
    }
    $this->deleteJson(V1::url('files/'.$file->id.'/revisions/'.$bad))
        ->assertNotFound()->assertJsonPath('code', 'node_not_found');
    $this->patchJson(V1::url('files/'.$file->id.'/revisions/'.$bad), ['keep_forever' => true])
        ->assertNotFound()->assertJsonPath('code', 'node_not_found');
    $this->postJson(V1::url('files/'.$file->id.'/revisions/'.$bad.'/restore'), [], ['If-Match' => DriveV1::etag($this, $file->id)])
        ->assertNotFound()->assertJsonPath('code', 'node_not_found');

    expect($bound)->toBe([]);
});

it('answers not_a_file for content routes on a folder', function (): void {
    $folder = V1::folder($this->drive, 'not-a-file');

    $this->getJson(V1::url('files/'.$folder->id.'/revisions'))->assertStatus(422)->assertJsonPath('code', 'not_a_file');
    $this->getJson(V1::url('files/'.$folder->id.'/download'))->assertStatus(422)->assertJsonPath('code', 'not_a_file');
});
