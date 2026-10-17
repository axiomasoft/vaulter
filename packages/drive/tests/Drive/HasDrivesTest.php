<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Vaulter\Drive\Concerns\HasDrives;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Jobs\PurgeDriveJob;
use Vaulter\Drive\Models\Drive;

final class HasDrivesHost extends Model
{
    use HasDrives;

    protected $table = 'has_drives_hosts';

    protected $guarded = [];

    public $timestamps = false;
}

final class HasDrivesSoftHost extends Model
{
    use HasDrives;
    use SoftDeletes;

    protected $table = 'has_drives_hosts';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function (): void {
    Queue::fake();
    Schema::create('has_drives_hosts', static function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
});

it('mounts drives through the trait: drive() provisions and drives() lists the morph', function (): void {
    $host = HasDrivesHost::query()->create(['name' => 'a']);

    $handle = $host->drive();
    $second = $host->drive('default');

    expect($handle->snapshot()->ownerId)->toBe((string) $host->getKey())
        ->and($second->snapshot()->id)->toBe($handle->snapshot()->id)
        ->and($host->drives()->count())->toBe(1)
        ->and($host->drives()->first()->getKey())->toEqual($handle->model()->getKey());
});

it('keeps the drives of a deleted owner by default', function (): void {
    $host = HasDrivesHost::query()->create();
    $host->drive();

    $host->delete();

    expect(Drive::query()->where('owner_type', $host->getMorphClass())->where('owner_id', $host->getKey())->value('status'))->toBe(DriveStatus::Active);
    Queue::assertNothingPushed();
});

it('deletes the drives of a deleted owner with vaulter.drives.delete_with_owner', function (): void {
    config()->set('vaulter.drives.delete_with_owner', true);
    $host = HasDrivesHost::query()->create();
    $host->drive();

    $host->delete();

    expect(Drive::query()->where('owner_type', $host->getMorphClass())->where('owner_id', $host->getKey())->value('status'))->toBe(DriveStatus::Deleting);
    Queue::assertPushed(PurgeDriveJob::class);
});

it('a host with SoftDeletes reacts to forceDeleted and not to deleted', function (): void {
    config()->set('vaulter.drives.delete_with_owner', true);
    $host = HasDrivesSoftHost::query()->create();
    $host->drive();
    $where = fn () => Drive::query()->where('owner_type', $host->getMorphClass())->where('owner_id', $host->getKey());

    $host->delete();
    expect($where()->value('status'))->toBe(DriveStatus::Active);
    Queue::assertNothingPushed();

    $host->forceDelete();
    expect($where()->value('status'))->toBe(DriveStatus::Deleting);
});
