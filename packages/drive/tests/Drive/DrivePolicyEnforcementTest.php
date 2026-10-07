<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\MoveOptions;
use Vaulter\Data\Drive\RestoreOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Actions\Uploads\InitiateUploadAction;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\DriveProvisioner;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Exceptions\Nodes\InvalidNodeNameException;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Exceptions\Policy\FileSizeLimitExceededException;
use Vaulter\Exceptions\Policy\FolderChildrenLimitExceededException;
use Vaulter\Exceptions\Policy\TreeDepthLimitExceededException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Node;
use Vaulter\Naming\PermissiveNamePolicy;
use Vaulter\Operations\OperationContext;
use Vaulter\Profiles\DrivePolicyBuilder;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Tests\Drive\DriveTestHelpers;

/** P4-I25 and P4-I17: the drive policy and name policy hold on every write path. */
function policyDrive(?callable $configure = null): DriveHandle
{
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default')->drivePolicy($configure ?? static fn (DrivePolicyBuilder $p) => $p));
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);
    app()->forgetInstance(DriveProvisioner::class);

    return Vaulter::drive(DriveTestHelpers::host())->asSystem('policy fixture');
}

function policyReason(callable $operation): ?string
{
    try {
        $operation();

        return null;
    } catch (InvalidNodeNameException $exception) {
        return $exception->reason;
    }
}

beforeEach(fn () => Storage::fake('local'));

it('applies portable-v1 to create, rename, upload and copy-as, with stable reasons', function (string $name, string $reason): void {
    $drive = policyDrive();
    $file = $drive->files()->upload(UploadSource::fromString('x', 'ok.txt'));

    expect(policyReason(fn () => $drive->folders()->create($name)))->toBe($reason)
        ->and(policyReason(fn () => $drive->nodes()->rename($file, $name)))->toBe($reason)
        ->and(policyReason(fn () => $drive->files()->upload(UploadSource::fromString('x', 'in.txt'), new UploadOptions(name: $name))))->toBe($reason)
        ->and(policyReason(fn () => $drive->nodes()->copy($file, null, new CopyOptions(name: $name))))->toBe($reason);
})->with([
    'leading space' => [' a.txt', 'leading_or_trailing_space'],
    'trailing dot' => ['a.txt.', 'trailing_dot_or_space'],
    'device name' => ['CON.txt', 'reserved_name'],
    'colon' => ['a:b', 'reserved_char'],
]);

it('accepts under permissive-v1 what portable-v1 refuses', function (): void {
    $drive = policyDrive(static fn (DrivePolicyBuilder $p) => $p->namePolicy(PermissiveNamePolicy::KEY));

    expect($drive->folders()->create('a:b')->name)->toBe('a:b')
        ->and($drive->folders()->create(' spaced ')->name)->toBe(' spaced ');
});

it('treats NFC/NFD and case variants as one sibling name and lets a node change its own case', function (): void {
    $drive = policyDrive();
    $drive->files()->upload(UploadSource::fromString('x', 'Résumé.pdf'));
    $report = $drive->files()->upload(UploadSource::fromString('x', 'report.pdf'));

    expect(fn () => $drive->files()->upload(UploadSource::fromString('y', "Re\u{0301}sume\u{0301}.pdf")))->toThrow(NodeNameConflictException::class)
        ->and(fn () => $drive->folders()->create('REPORT.pdf'))->toThrow(NodeNameConflictException::class)
        ->and($drive->nodes()->rename($report, 'Report.pdf')->name)->toBe('Report.pdf');
});

it('never renames a stored name the policy refuses, but every new name passes it', function (): void {
    $drive = policyDrive();
    $legacy = $drive->folders()->create('legacy');
    $legacy->forceFill(['name' => 'CON.txt'])->save();
    $target = $drive->folders()->create('target');

    // Moving keeps the stored name untouched; renaming to it is refused.
    expect($drive->nodes()->move($legacy, $target)->name)->toBe('CON.txt')
        ->and(policyReason(fn () => $drive->nodes()->rename($legacy, 'CON.txt ')))->toBe('leading_or_trailing_space');
});

it('enforces maxFolderChildren on create, upload, move, copy and restore — trashed children included', function (): void {
    $drive = policyDrive(static fn (DrivePolicyBuilder $p) => $p->maxFolderChildren(3));
    $full = $drive->folders()->create('full');
    $drive->folders()->create('a', $full);
    $drive->folders()->create('b', $full);
    $drive->nodes()->trash($drive->folders()->create('c', $full));
    $outside = $drive->folders()->create('outside');
    $other = $drive->folders()->create('other');
    $child = $drive->folders()->create('child', $other);
    $drive->nodes()->trash($child);
    $drive->nodes()->trash($other);

    expect(fn () => $drive->folders()->create('d', $full))->toThrow(FolderChildrenLimitExceededException::class)
        ->and(fn () => $drive->files()->upload(UploadSource::fromString('x', 'd.txt'), new UploadOptions(parent: $full)))->toThrow(FolderChildrenLimitExceededException::class)
        ->and(fn () => $drive->nodes()->move($outside, $full))->toThrow(FolderChildrenLimitExceededException::class)
        ->and(fn () => $drive->nodes()->copy($outside, $full))->toThrow(FolderChildrenLimitExceededException::class)
        ->and(fn () => $drive->trash()->restore($child, new RestoreOptions(destination: $full)))->toThrow(FolderChildrenLimitExceededException::class)
        ->and(Node::query()->where('parent_id', $full->id)->count())->toBe(3);
});

it('enforces maxFolderDepth on create, upload, move, copy and restore', function (): void {
    $drive = policyDrive(static fn (DrivePolicyBuilder $p) => $p->maxFolderDepth(2));
    $one = $drive->folders()->create('one');
    $two = $drive->folders()->create('two', $one);
    $branch = $drive->folders()->create('branch');
    $drive->folders()->create('leaf', $branch);
    $loose = $drive->folders()->create('loose');
    $holder = $drive->folders()->create('holder');
    $trashed = $drive->folders()->create('trashed', $holder);
    $drive->nodes()->trash($trashed);
    $drive->nodes()->trash($holder);

    expect(fn () => $drive->folders()->create('three', $two))->toThrow(TreeDepthLimitExceededException::class)
        ->and(fn () => $drive->files()->upload(UploadSource::fromString('x', 'deep.txt'), new UploadOptions(parent: $two)))->toThrow(TreeDepthLimitExceededException::class)
        ->and(fn () => $drive->nodes()->move($branch, $one))->toThrow(TreeDepthLimitExceededException::class)
        ->and(fn () => $drive->nodes()->copy($branch, $one))->toThrow(TreeDepthLimitExceededException::class)
        ->and(fn () => $drive->trash()->restore($trashed, new RestoreOptions(destination: $two)))->toThrow(TreeDepthLimitExceededException::class)
        ->and($drive->nodes()->move($loose, $one)->parent_id)->toBe($one->id);
});

it('checks the declared, streamed and final file size against maxFileBytes', function (): void {
    $drive = policyDrive(static fn (DrivePolicyBuilder $p) => $p->maxFileBytes(4));
    $context = OperationContext::system($drive->model(), 'policy fixture');

    expect(fn () => $drive->files()->upload(UploadSource::fromString('12345', 'big.txt')))->toThrow(FileSizeLimitExceededException::class)
        ->and(fn () => app(InitiateUploadAction::class)->execute($context, new InitiateUploadInput(protocol: UploadProtocol::Tus, targetParent: null, targetName: 'big.txt', totalSizeBytes: 5)))
        ->toThrow(FileSizeLimitExceededException::class)
        ->and($drive->files()->upload(UploadSource::fromString('1234', 'fits.txt'))->size_bytes)->toBe(4);
});

it('renders the limit problems with their stable codes', function (): void {
    $drive = policyDrive(static fn (DrivePolicyBuilder $p) => $p->maxFolderChildren(1));
    $drive->folders()->create('only');

    try {
        $drive->folders()->create('second');
        $this->fail('The children limit did not apply.');
    } catch (FolderChildrenLimitExceededException $exception) {
        expect($exception->problemCode())->toBe('folder_children_limit_exceeded')->and($exception->httpStatus())->toBe(409);
    }
    expect(FileSizeLimitExceededException::forLimit(1)->httpStatus())->toBe(413)
        ->and(TreeDepthLimitExceededException::forDepth(3, 2)->problemCode())->toBe('tree_depth_limit_exceeded');
});

it('resolves a conflict rename with a name the policy accepts', function (): void {
    $drive = policyDrive();
    $drive->folders()->create('notes');
    $folder = $drive->folders()->create('elsewhere');
    $moving = $drive->folders()->create('notes', $folder);

    expect($drive->nodes()->move($moving, null, new MoveOptions(onConflict: OnNameConflict::Rename))->name)->toBe('notes (2)');
});
