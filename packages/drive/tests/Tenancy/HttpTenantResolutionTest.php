<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Repositories\Uploads\UploadSessionReadRepository;
use Vaulter\Drive\Tests\TestCase;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Facades\Vaulter;
use Vaulter\Repositories\Drive\FileRevisionReadRepository;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Tenancy\MutableTenantResolver;

uses(TestCase::class, RefreshDatabase::class);

afterEach(fn () => MutableTenantResolver::clearFixtureStamps());

it('V31 actual HTTP node revision and upload session routes cannot cross tenant', function (): void {
    Storage::fake('local');
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    $resolver = new MutableTenantResolver;
    app()->instance(TenantResolver::class, $resolver);
    $handle = Vaulter::drive(DriveTestHelpers::host())->asSystem('fixture');
    $file = $handle->files()->upload(UploadSource::fromString('bytes', 'private.txt'));
    $revision = DriveTestHelpers::model($file)->file()->firstOrFail()->currentRevision()->firstOrFail();
    $this->actingAs(AccessTestHelpers::user(10));
    $session = $this->postJson('api/vaulter/v1/drives/'.$handle->model()->id.'/uploads', ['protocol' => 'tus', 'name' => 'resumed.txt', 'total_size_bytes' => 4])->assertCreated()->json('data.upload_id');
    expect(DriveTestHelpers::model($file)->tenant_id)->toBe('A')->and($revision->tenant_id)->toBe('A');
    $driveId = $handle->model()->id;
    $resolver->tenant = 'B';
    $this->getJson('api/vaulter/v1/drives/'.$driveId)->assertNotFound()->assertJsonPath('code', 'drive_not_found');
    $this->getJson('api/vaulter/v1/nodes/'.$file->id)->assertNotFound();
    $this->getJson('api/vaulter/v1/files/'.$file->id.'/revisions')->assertNotFound();
    $this->call('HEAD', 'api/vaulter/v1/uploads/'.$session, [], [], [], ['HTTP_TUS_RESUMABLE' => '1.0.0'])->assertNotFound();
    expect(app(UploadSessionReadRepository::class)->findByUploadId($session))->toBeNull()
        ->and(fn () => app(FileRevisionReadRepository::class)->head(DriveTestHelpers::model($file)))->toThrow(NodeNotFoundException::class);
    $resolver->tenant = 'A';
    $this->getJson('api/vaulter/v1/nodes/'.$file->id)->assertOk();
});
