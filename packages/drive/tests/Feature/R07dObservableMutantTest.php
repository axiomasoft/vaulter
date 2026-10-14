<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Illuminate\Validation\ValidationException;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\PermissionDriverManager;
use Vaulter\Access\Roles\Role;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Contracts\Access\PermissionDriver;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Tests\Http\DriveV1;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\S3MultipartHandler;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Access\Permission;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;
use Vaulter\Support\LoadTier;
use Vaulter\Testing\FakeMetricsRecorder;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

beforeEach(fn () => DriveTestHelpers::insideOperation());

/**
 * Role lane whose allow-mask is the only reason a guard passes or fails.
 * NullPermissionDriver allows every ability, so removing one guard stays invisible.
 */
final class R07dBitsDriver implements PermissionDriver
{
    public function __construct(
        private readonly int $bits,
        private readonly bool $workspaceCreate,
    ) {}

    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision
    {
        if ($this->bits === 0) {
            return null;
        }

        // An ad-hoc role carrying exactly the bits under test.
        return new Decision(role: new Role(key: 'r07d', version: 1, abilities: Ability::fromMask($this->bits)));
    }

    public function override(Authorizable $actor, AccessScope $scope): ?Effect
    {
        return null;
    }

    /**
     * @return list<Subject>
     */
    public function subjectsFor(Authorizable $actor): array
    {
        if (!$actor instanceof Model) {
            return [];
        }

        return [Subject::ofModel($actor)];
    }

    public function canCreateIn(Authorizable $actor, AccessScope $scope): bool
    {
        return $this->workspaceCreate;
    }
}

/**
 * Body whose fstat size is 0, so the cheap size pre-check cannot see it.
 * The copy itself is what has to refuse an oversize chunk.
 */
final class R07dUnsizedStream
{
    public string $data = '';

    public int $position = 0;

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $context = is_resource($this->context) ? stream_context_get_options($this->context) : [];
        $this->data = (string) ($context['r07d']['bytes'] ?? '');
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->data, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->data);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => 0];
    }
}

beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
    $this->actingAs((new TestUser)->forceFill(['id' => 41]));

    if (!in_array('r07d', stream_get_wrappers(), true)) {
        stream_wrapper_register('r07d', R07dUnsizedStream::class);
    }
});

function r07dStorage(): Drive
{
    return AccessTestHelpers::storage();
}

function r07dInstall(int $bits, bool $workspaceCreate): void
{
    app()->instance(R07dBitsDriver::class, new R07dBitsDriver(
        bits: $bits,
        workspaceCreate: $workspaceCreate,
    ));
    config()->set('vaulter.access.permission_drivers.r07d', R07dBitsDriver::class);
    config()->set('vaulter.access.permission_driver', 'r07d');
    app()->forgetInstance(PermissionDriverManager::class);
    app()->forgetInstance(AccessResolver::class);
}

function r07dMetrics(): void
{
    config()->set('vaulter.observability.enabled', true);
    config()->set('vaulter.observability.recorder', FakeMetricsRecorder::class);
    app()->forgetInstance(MetricsRecorder::class);
    LoadTier::set('S');
}

/**
 * @return list<array{type: string, name: string, value: int|float, labels: array<string, string>}>
 */
function r07dEmissions(string $name): array
{
    $recorder = app(MetricsRecorder::class);

    expect($recorder)->toBeInstanceOf(FakeMetricsRecorder::class);

    return array_values(array_filter(
        $recorder->emissions(),
        static fn (array $emission): bool => $emission['name'] === $name,
    ));
}

/**
 * @return resource
 */
function r07dUnsized(string $bytes)
{
    $handle = fopen('r07d://chunk', 'rb', false, stream_context_create([
        'r07d' => ['bytes' => $bytes],
    ]));

    expect($handle)->toBeResource();

    return $handle;
}

it('rejects a stat-able chunk when the ceiling is 1 byte', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 1);
    $session = UploadTestHelpers::session(UploadTestHelpers::storage());

    expect(fn () => app(StagedUpload::class)->append($session, UploadTestHelpers::streamFor('AB'), 0))
        ->toThrow(ValidationException::class, 'The upload chunk of 2 bytes exceeds');

    expect(app(StagedUpload::class)->size($session->refresh()))->toBe(0);
});

it('rejects an unstatable chunk when the ceiling is 1 byte and stages nothing', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 1);
    $session = UploadTestHelpers::session(UploadTestHelpers::storage());

    expect(fn () => app(StagedUpload::class)->append($session, r07dUnsized('AB'), 0))
        ->toThrow(ValidationException::class);

    expect(app(StagedUpload::class)->size($session->refresh()))->toBe(0);
});

it('rejects an s3 part of 2 bytes when the ceiling is 1 byte', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 1);
    $storage = UploadTestHelpers::storage();
    $session = app(UploadSessionManager::class)->initiate(context: DriveContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::S3Multipart,
        targetParent: $storage->rootNode()->first(),
        targetName: 'one.bin',
        totalSizeBytes: 2,
        mimeType: 'application/octet-stream',
    ));

    expect(fn () => app(S3MultipartHandler::class)->putPart(
        session: $session->refresh(),
        partNo: 1,
        byteOffset: 0,
        chunk: UploadTestHelpers::streamFor('AB'),
    ))->toThrow(ValidationException::class, 'maximum chunk size of 1 bytes');

    expect(UploadPart::query()->count())->toBe(0);
});

it('records an assemble duration of a few seconds, not the nanosecond product', function (): void {
    r07dMetrics();
    $storage = UploadTestHelpers::storage();
    $session = app(UploadSessionManager::class)->initiate(context: DriveContext::system($storage, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::S3Multipart,
        targetParent: $storage->rootNode()->first(),
        targetName: 'asm.bin',
        totalSizeBytes: 4,
        mimeType: 'application/octet-stream',
    ));
    $handler = app(S3MultipartHandler::class);
    $handler->putPart(
        session: $session->refresh(),
        partNo: 1,
        byteOffset: 0,
        chunk: UploadTestHelpers::streamFor('abcd'),
    );
    $handler->complete($session->refresh());

    $durations = r07dEmissions('vaulter_assemble_duration_seconds');

    expect($durations)->toHaveCount(1)
        ->and($durations[0]['value'])->toBeGreaterThanOrEqual(0)
        ->and($durations[0]['value'])->toBeLessThan(30);
});

it('counts a 10-byte download as one 10-byte chunk', function (): void {
    r07dMetrics();
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
    $file = DriveTestHelpers::drive()->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('0123456789'), 'ten.bin'));

    $response = $this->get('api/vaulter/v1/files/'.$file->id.'/download');
    $response->assertOk();
    expect($response->streamedContent())->toBe('0123456789');

    $counters = r07dEmissions('vaulter_download_bytes_total');

    expect($counters)->toHaveCount(1)
        ->and($counters[0]['value'])->toBe(10);
});

it('records download ttfb once for a body larger than one read', function (): void {
    r07dMetrics();
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
    $bytes = str_repeat('x', (1024 * 256) + 1);
    $file = DriveTestHelpers::drive()->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor($bytes), 'wide.bin'));

    $response = $this->get('api/vaulter/v1/files/'.$file->id.'/download');
    $response->assertOk();
    expect(strlen($response->streamedContent()))->toBe(strlen($bytes));

    $samples = r07dEmissions('vaulter_download_ttfb_seconds');

    expect($samples)->toHaveCount(1)
        ->and($samples[0]['value'])->toBeGreaterThanOrEqual(0)
        ->and($samples[0]['value'])->toBeLessThan(30);
});

it('keeps the source original name on a copied version', function (): void {
    $drive = DriveTestHelpers::drive();
    $source = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('abc'), 'kept-name.txt'));
    $copy = $drive->nodes()->copy(node: $source, options: new CopyOptions(name: 'other.txt'));

    expect(DriveTestHelpers::model($copy)->file->currentRevision->original_name)->toBe('kept-name.txt');
});

it('hides move copy and revision sources when Create is present but Read is absent', function (): void {
    $storage = r07dStorage();
    $base = 'api/vaulter/v1/drives/'.$storage->getKey();
    $nodeId = (string) $this->postJson($base.'/files', [
        'file' => UploadedFile::fake()->createWithContent('src.txt', 'src'),
    ])->assertCreated()->json('data.id');
    $folderId = (string) $this->postJson($base.'/folders', ['name' => 'Dest'])->assertCreated()->json('data.id');

    r07dInstall(bits: Permission::Create->value, workspaceCreate: true);

    $this->postJson('api/vaulter/v1/nodes/'.$nodeId.'/move', ['destination_id' => $folderId], DriveV1::ifMatch($nodeId))->assertNotFound()->assertJsonPath('code', 'node_not_found');
    $this->postJson('api/vaulter/v1/nodes/'.$nodeId.'/copy', ['name' => 'copy.txt'])->assertNotFound()->assertJsonPath('code', 'node_not_found');
    $this->postJson('api/vaulter/v1/files/'.$nodeId.'/revisions', [
        'file' => UploadedFile::fake()->createWithContent('next.txt', 'next'),
    ], DriveV1::ifMatch($nodeId))->assertNotFound()->assertJsonPath('code', 'node_not_found');
});

it('refuses move and copy when workspace create is closed even if the node bits allow them', function (): void {
    $storage = r07dStorage();
    $base = 'api/vaulter/v1/drives/'.$storage->getKey();
    $nodeId = (string) $this->postJson($base.'/files', [
        'file' => UploadedFile::fake()->createWithContent('src.txt', 'src'),
    ])->assertCreated()->json('data.id');
    $folderId = (string) $this->postJson($base.'/folders', ['name' => 'Dest'])->assertCreated()->json('data.id');

    r07dInstall(bits: (new RoleRegistry)->latest(RoleRegistry::WRITER)->bits, workspaceCreate: false);

    $this->postJson('api/vaulter/v1/nodes/'.$nodeId.'/move', ['destination_id' => $folderId], DriveV1::ifMatch($nodeId))->assertForbidden();
    $this->postJson('api/vaulter/v1/nodes/'.$nodeId.'/copy', [
        'name' => 'copy.txt',
        'destination_id' => $folderId,
    ])->assertForbidden();
});

it('authorizes the requested parent, not the storage root', function (): void {
    $storage = r07dStorage();
    $base = 'api/vaulter/v1/drives/'.$storage->getKey();
    $folderId = (string) $this->postJson($base.'/folders', ['name' => 'Owned'])->assertCreated()->json('data.id');
    $nodeId = (string) $this->postJson($base.'/files', [
        'file' => UploadedFile::fake()->createWithContent('src.txt', 'src'),
    ])->assertCreated()->json('data.id');

    // Writer on the folder and the file only; nothing on the root. The driver lane is
    // node-blind, so this case runs on the database driver's stored grants.
    config()->set('vaulter.access.permission_driver', 'database');
    app()->forgetInstance(PermissionDriverManager::class);
    app()->forgetInstance(AccessResolver::class);
    AccessTestHelpers::grant(Node::query()->findOrFail($folderId), RoleRegistry::WRITER, subjectId: 41);
    AccessTestHelpers::grant(Node::query()->findOrFail($nodeId), RoleRegistry::WRITER, scope: GrantScope::Item, subjectId: 41);

    $this->postJson($base.'/folders', [
        'name' => 'Child',
        'parent_id' => $folderId,
    ])->assertCreated();
    $this->postJson($base.'/files', [
        'parent_id' => $folderId,
        'file' => UploadedFile::fake()->createWithContent('in.txt', 'in'),
    ])->assertCreated();
    $this->postJson('api/vaulter/v1/nodes/'.$nodeId.'/move', ['destination_id' => $folderId], DriveV1::ifMatch($nodeId))->assertOk();
    $this->postJson('api/vaulter/v1/nodes/'.$nodeId.'/copy', [
        'name' => 'copy.txt',
        'destination_id' => $folderId,
    ])->assertCreated();
});

it('counts one purged trash root from zero', function (): void {
    $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
    $storage = r07dStorage();
    $node = AccessTestHelpers::folder($storage, null, 'r07d-one');
    $node->forceFill(['trashed_at' => Carbon::now()->subDays(2), 'trash_root_id' => $node->getKey()])->save();

    Artisan::call('vaulter:trash:purge', ['--days' => '1']);

    expect(Artisan::output())->toContain('Purged 1 trashed subtree.');
});

it('does not count a trash root that is restored before the lock', function (): void {
    $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
    $storage = r07dStorage();
    $node = AccessTestHelpers::folder($storage, null, 'r07d-restored');
    $node->forceFill(['trashed_at' => Carbon::now()->subDays(2), 'trash_root_id' => $node->getKey()])->save();
    $id = $node->getKey();
    $armed = true;

    Node::retrieved(function (Node $retrieved) use (&$armed, $id): void {
        if (!$armed || (string) $retrieved->getKey() !== (string) $id) {
            return;
        }

        $armed = false;
        Node::query()->whereKey($id)->update(['trashed_at' => null]);
    });

    Artisan::call('vaulter:trash:purge', ['--days' => '1']);

    expect(Artisan::output())->toContain('Purged 0 trashed subtrees.')
        ->and(Node::query()->whereKey($id)->exists())->toBeTrue();
});

it('does not emit a purge counter when nothing was purged', function (): void {
    r07dMetrics();

    Artisan::call('vaulter:trash:purge', ['--days' => '1']);

    expect(array_column(r07dEmissions('vaulter_purge_nodes_total'), 'name'))->toBe([]);
});

it('floors negative retention at zero and purges a root trashed now', function (): void {
    $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
    $storage = r07dStorage();
    $trashed = AccessTestHelpers::folder($storage, null, 'negative-days');
    $live = AccessTestHelpers::folder($storage, null, 'still-live');
    $trashed->forceFill(['trashed_at' => Carbon::now(), 'trash_root_id' => $trashed->getKey()])->save();

    expect(Artisan::call('vaulter:trash:purge', ['--days' => '-3']))->toBe(0)
        ->and(Artisan::output())->toContain('Purged 1 trashed subtree.')
        ->and(Node::query()->whereKey($trashed->id)->exists())->toBeFalse()
        ->and(Node::query()->whereKey($live->id)->exists())->toBeTrue();
});
