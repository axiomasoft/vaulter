<?php

declare(strict_types=1);

use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Http\Requests\Uploads\CreateUploadSessionRequest;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Http\DriveV1;
use Vaulter\Testing\Fixtures\TestUser;

beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
    config()->set('vaulter-drive.uploads.allowed_mime_types', []);
    $this->actingAs((new TestUser)->forceFill(['id' => 17]));
});

function r07ResumableBaseline(): array
{
    return [
        'protocol' => 'tus',
        'name' => 'baseline.bin',
        'total_size_bytes' => 4,
    ];
}

function r07ResumableUrl(string|int $storageId): string
{
    return 'api/vaulter/v1/drives/'.$storageId.'/uploads';
}

function r07StorageId(): string|int
{
    return Vaulter::drive(DriveTestHelpers::host())->model()->getKey();
}

function r07NameSeparatorMessage(): string
{
    return 'The name must not contain path separators (/ or \\).';
}

it('R07Request accepts nullable resumable fields at their boundaries', function (): void {
    $storageId = r07StorageId();
    $sha256 = str_repeat('a', 64);
    $name255 = str_repeat('n', 255);

    $this->postJson(r07ResumableUrl($storageId), [
        'protocol' => null,
        'parent_id' => null,
        'node_id' => null,
        'name' => null,
        'total_size_bytes' => null,
        'expected_sha256' => null,
        'mime_type' => null,
        'metadata' => null,
    ])->assertCreated();

    $this->postJson(r07ResumableUrl($storageId), [
        'name' => $name255,
        'total_size_bytes' => 0,
        'expected_sha256' => $sha256,
        'mime_type' => str_repeat('m', 191),
    ])->assertCreated();
});

it('R07Request rejects each malformed resumable field in isolation', function (array $override, string $field): void {
    $response = $this->postJson(r07ResumableUrl(r07StorageId()), array_merge(r07ResumableBaseline(), $override))
        ->assertUnprocessable();

    expect(array_keys($response->json('errors')))->toBe([$field]);
})->with([
    'protocol enum' => [['protocol' => 'not-a-protocol'], 'protocol'],
    'name type' => [['name' => 123], 'name'],
    'name max 256' => [['name' => str_repeat('n', 256)], 'name'],
    'name separator' => [['name' => 'bad/name'], 'name'],
    'total_size_bytes type' => [['total_size_bytes' => 'not-int'], 'total_size_bytes'],
    'total_size_bytes min -1' => [['total_size_bytes' => -1], 'total_size_bytes'],
    'expected_sha256 size 63' => [['expected_sha256' => str_repeat('b', 63)], 'expected_sha256'],
    'mime max 192' => [['mime_type' => str_repeat('m', 192)], 'mime_type'],
    'metadata not array' => [['metadata' => 'scalar'], 'metadata'],
]);

it('R07Request surfaces the resumable name separator custom message', function (): void {
    $response = $this->postJson(r07ResumableUrl(r07StorageId()), array_merge(r07ResumableBaseline(), [
        'name' => 'a\\b',
    ]))->assertUnprocessable();

    expect($response->json('errors.name.0'))->toBe(r07NameSeparatorMessage());
});

it('R07Request resolves resumable protocol from input with tus default', function (): void {
    $default = CreateUploadSessionRequest::createFrom(HttpRequest::create('/', 'POST', []));
    expect($default->protocol())->toBe(UploadProtocol::Tus);

    $explicit = CreateUploadSessionRequest::createFrom(HttpRequest::create('/', 'POST', [
        'protocol' => 's3-multipart',
    ]));
    expect($explicit->protocol())->toBe(UploadProtocol::S3Multipart);

    $this->postJson(r07ResumableUrl(r07StorageId()), [
        'name' => 'default-protocol.bin',
        'total_size_bytes' => 4,
    ])->assertCreated()
        ->assertJsonPath('data.protocol', 'tus');
});

it('R07Request treats an empty resumable name as absent while a string name is stored', function (): void {
    $storageId = r07StorageId();

    $emptyNameId = $this->postJson(r07ResumableUrl($storageId), [
        'name' => '',
        'total_size_bytes' => 4,
    ])->assertCreated()->json('data.upload_id');

    expect(UploadSession::query()->where('upload_id', $emptyNameId)->sole()->target_name)->toBe('upload.bin');

    $namedId = $this->postJson(r07ResumableUrl($storageId), [
        'name' => 'stored.bin',
        'total_size_bytes' => 4,
    ])->assertCreated()->json('data.upload_id');

    expect(UploadSession::query()->where('upload_id', $namedId)->sole()->target_name)->toBe('stored.bin');
});

it('R07Request keeps only scalar metadata values in CreateUploadSessionRequest', function (): void {
    $http = HttpRequest::create('/resumable-uploads', 'POST', [
        'metadata' => [
            'filename' => 'meta.bin',
            'ignored_object' => ['nested' => 'value'],
            'ignored_int' => 7,
        ],
    ]);
    $http->headers->set('Accept', 'application/json');

    $request = CreateUploadSessionRequest::createFrom($http);
    $request->setContainer(app());

    expect($request->metadata())->toBe([
        'filename' => 'meta.bin',
        'ignored_int' => '7',
    ]);
});

it('R07Request validates multipart upload file parent and name rules', function (): void {
    $drive = DriveTestHelpers::drive();
    $storage = $drive->model();
    $base = 'api/vaulter/v1/drives/'.$storage->getKey().'/files';

    $this->postJson($base, [
        'file' => UploadedFile::fake()->createWithContent('a.txt', 'bytes'),
        'parent_id' => null,
    ])->assertCreated()
        ->assertJsonPath('data.parent_id', (string) $storage->root_node_id);

    $response = $this->postJson($base, [
        'file' => UploadedFile::fake()->createWithContent('b.txt', 'bytes'),
        'name' => str_repeat('x', 256),
    ])->assertUnprocessable();

    expect(array_keys($response->json('errors')))->toBe(['name']);

    $response = $this->postJson($base, [
        'file' => UploadedFile::fake()->createWithContent('c.txt', 'bytes'),
        'name' => 'bad/name',
    ])->assertUnprocessable();

    expect($response->json('errors.name.0'))->toBe(r07NameSeparatorMessage());

    $this->postJson($base, [
        'file' => UploadedFile::fake()->createWithContent('d.txt', 'bytes'),
        'name' => '',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'd.txt');

    $this->postJson($base, [
        'file' => UploadedFile::fake()->createWithContent('e.txt', 'bytes'),
        'name' => str_repeat('y', 255),
    ])->assertCreated()
        ->assertJsonPath('data.name', str_repeat('y', 255));
});

it('R07Request requires the multipart upload file field alone when missing', function (): void {
    $response = $this->postJson('api/vaulter/v1/drives/'.r07StorageId().'/files', [
        'parent_id' => null,
    ])->assertUnprocessable();

    expect(array_keys($response->json('errors')))->toBe(['file']);
});

it('R07Request validates copy name boundaries and separator message in isolation', function (): void {
    $drive = DriveTestHelpers::drive();
    $dest = $drive->folders()->create('CopyDest');
    $file = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('copy'), 'source.bin'), new UploadOptions(parent: $dest));
    $uri = 'api/vaulter/v1/nodes/'.$file->id.'/copy';

    $response = $this->postJson($uri, ['parent_id' => $dest->id, 'name' => str_repeat('c', 256)])->assertUnprocessable();
    expect(array_keys($response->json('errors')))->toBe(['name']);

    $response = $this->postJson($uri, ['parent_id' => $dest->id, 'name' => 'a/b'])->assertUnprocessable();
    expect($response->json('errors.name.0'))->toBe(r07NameSeparatorMessage());

    $this->postJson($uri, ['parent_id' => $dest->id, 'name' => str_repeat('d', 255)])->assertCreated();
});

it('R07Request accepts nullable copy parent_id and empty name override as null', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('Holder');
    $file = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('x'), 'keep-name.bin'), new UploadOptions(parent: $folder));
    $uri = 'api/vaulter/v1/nodes/'.$file->id.'/copy';

    $copy = $this->postJson($uri, ['parent_id' => null, 'name' => ''])->assertCreated()->json('data');

    expect($copy['name'])->toBe('keep-name.bin');
});

it('R07Request accepts nullable move parent_id only', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('Hold');
    $node = $drive->folders()->create('Movable', $folder);

    $this->postJson('api/vaulter/v1/nodes/'.$node->id.'/move', ['destination_id' => null], DriveV1::ifMatch($node->id))
        ->assertOk()
        ->assertJsonPath('data.parent_id', (string) $drive->model()->root_node_id);
});

it('R07Request validates rename name rules and custom separator message', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->folders()->create('Before');
    $uri = 'api/vaulter/v1/nodes/'.$node->id;

    $response = $this->patchJson($uri, ['name' => ''])->assertUnprocessable();
    expect(array_keys($response->json('errors')))->toBe(['name']);

    $response = $this->patchJson($uri, ['name' => str_repeat('r', 256)])->assertUnprocessable();
    expect(array_keys($response->json('errors')))->toBe(['name']);

    $response = $this->patchJson($uri, ['name' => 'a\\b'])->assertUnprocessable();
    expect($response->json('errors.name.0'))->toBe(r07NameSeparatorMessage());

    $this->patchJson($uri, ['name' => str_repeat('r', 255)], DriveV1::ifMatch($node->id))->assertOk()
        ->assertJsonPath('data.name', str_repeat('r', 255));
});

it('R07Request validates create-folder name and nullable parent_id', function (): void {
    $drive = DriveTestHelpers::drive();
    $storage = $drive->model();
    $base = 'api/vaulter/v1/drives/'.$storage->getKey().'/folders';
    $child = $drive->folders()->create('Parent');

    $response = $this->postJson($base, ['name' => 'a/b'])->assertUnprocessable();
    expect($response->json('errors.name.0'))->toBe(r07NameSeparatorMessage());

    $response = $this->postJson($base, ['name' => str_repeat('f', 256)])->assertUnprocessable();
    expect(array_keys($response->json('errors')))->toBe(['name']);

    $this->postJson($base, ['name' => str_repeat('f', 255), 'parent_id' => null])
        ->assertCreated()
        ->assertJsonPath('data.parent_id', (string) $storage->root_node_id);

    $this->postJson($base, ['name' => 'Nested', 'parent_id' => $child->id])
        ->assertCreated()
        ->assertJsonPath('data.parent_id', $child->id);
});

it('R07Request enforces the dedicated upload size ceiling on the file rule only', function (): void {
    config()->set('vaulter-drive.uploads.max_file_size', 1024);
    $base = 'api/vaulter/v1/drives/'.r07StorageId().'/files';

    $response = $this->postJson($base, [
        'file' => UploadedFile::fake()->create('big.bin', 4),
    ])->assertUnprocessable();

    expect(array_keys($response->json('errors')))->toBe(['file']);
    expect(Node::query()->where('kind', 'file')->count())->toBe(0);
});

it('R07Request adds mimetypes to the file rule only when an allowlist is configured', function (): void {
    config()->set('vaulter-drive.uploads.allowed_mime_types', ['application/pdf']);
    $base = 'api/vaulter/v1/drives/'.r07StorageId().'/files';

    $response = $this->postJson($base, [
        'file' => UploadedFile::fake()->create('note.txt', 1, 'text/plain'),
    ])->assertUnprocessable();

    expect(array_keys($response->json('errors')))->toBe(['file']);

    $this->postJson($base, [
        'file' => UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n"),
    ])->assertCreated();
});
