<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Content\ScanPolicyResolver;
use Vaulter\Enums\Sharing\ShareAudience;
use Vaulter\Exceptions\Access\UnknownRoleException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Sharing\ShareRoleNotAllowedException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Operations\OperationContext;
use Vaulter\Operations\ShareOperations;
use Vaulter\Sharing\ShareResolver;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Concurrency\ForkRace;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Sharing\ShareTestHelpers;
use Vaulter\ValueObjects\Access\Actor;

beforeEach(function (): void {
    config()->set('vaulter.features.share_links', true);
    config()->set('vaulter.access.permission_driver', 'database');
});

it('rejects foreign share identity even when a model claims own node membership', function (): void {
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($drive, null, 'owner');
    $other = AccessTestHelpers::folder($drive, null, 'other');
    AccessTestHelpers::grant($node, RoleRegistry::OWNER);
    $share = ShareTestHelpers::share($other);
    $forged = clone $share;
    $forged->node_id = $node->id;
    $operation = app()->makeWith(ShareOperations::class, ['context' => new OperationContext($drive, Actor::user(AccessTestHelpers::user()), 'foreign-test')]);
    expect(fn () => $operation->revoke($node->id, $forged->getKey()))->toThrow(NodeNotFoundException::class)
        ->and($share->fresh()->revoked_at)->toBeNull();
});

it('rejects invalid direct share inputs before minting or recording', function (): void {
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($drive, null, 'validation');
    $operation = app()->makeWith(ShareOperations::class, ['context' => OperationContext::system($drive, 'fixture')]);
    foreach ([new ShareLinkInput(maxUses: 0), new ShareLinkInput(expiresAt: now()->subSecond()), new ShareLinkInput(expiresAt: now()->addYears(10)), new ShareLinkInput(audience: ShareAudience::Tenant)] as $input) {
        expect(fn () => $operation->create($node->id, $input))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => $operation->create($node->id, new ShareLinkInput(role: 'admin')))->toThrow(UnknownRoleException::class);
    foreach ([[RoleRegistry::WRITER, ShareAudience::Anyone], [RoleRegistry::ORGANIZER, ShareAudience::Authenticated], [RoleRegistry::OWNER, ShareAudience::Tenant]] as [$role, $audience]) {
        expect(fn () => $operation->create($node->id, new ShareLinkInput(role: $role, audience: $audience)))->toThrow(ShareRoleNotAllowedException::class);
    }
    expect(NodeShare::query()->count())->toBe(0);
});

it('denies every new resolution after revoke commit on independent connections', function (): void {
    // SQLite qualifies serial liveness only; the dedicated PostgreSQL lane proves the interleaving.
    if (DB::connection()->getDriverName() === 'pgsql') {
        expect(ForkRace::supported())->toBeTrue();
        DB::connection()->commit(); // Commit RefreshDatabase's wrapper before cross-connection fixtures.
    }
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($drive, null, 'linearization');
    $operation = app()->makeWith(ShareOperations::class, ['context' => OperationContext::system($drive, 'fixture')]);
    $minted = $operation->create($node->id, new ShareLinkInput);
    expect(app(ShareResolver::class)->resolve($minted->token)->isOk())->toBeTrue();
    if (DB::connection()->getDriverName() === 'pgsql') {
        $marker = sys_get_temp_dir().'/share-commit-'.bin2hex(random_bytes(8));
        try {
            $outcomes = ForkRace::run([
                function () use ($drive, $node, $minted, $marker): array {
                    app()->makeWith(ShareOperations::class, ['context' => OperationContext::system($drive, 'race revoke')])->revoke($node->id, $minted->link->id);
                    file_put_contents($marker, 'committed');

                    return ['pid' => getmypid(), 'backend' => DB::selectOne('select pg_backend_pid() as pid')->pid];
                },
                function () use ($minted, $marker): array {
                    $deadline = microtime(true) + 5;
                    while (!is_file($marker)) {
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException('revoke did not commit');
                        }
                        usleep(1000);
                    }
                    $response = app(ShareResolver::class)->resolve($minted->token);

                    return ['pid' => getmypid(), 'backend' => DB::selectOne('select pg_backend_pid() as pid')->pid, 'visible' => $response->isOk()];
                },
            ]);
            expect($outcomes[0]->ok)->toBeTrue($outcomes[0]->message ?? '')->and($outcomes[1]->ok)->toBeTrue($outcomes[1]->message ?? '')
                ->and($outcomes[0]->value['backend'])->not->toBe($outcomes[1]->value['backend'])
                ->and($outcomes[1]->value['visible'])->toBeFalse();
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    } else {
        $operation->revoke($node->id, $minted->link->id);
    }
    expect(app(ShareResolver::class)->resolve($minted->token)->isOk())->toBeFalse();
});

it('checks the public download availability hook before opening bytes and keeps metadata visible', function (): void {
    Storage::fake('local');
    $drive = Vaulter::drive(DriveTestHelpers::host())->asSystem('test fixture');
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('quarantined'), 'sample.txt'));
    $revision = Node::query()->findOrFail($node->id)->file()->firstOrFail()->currentRevision()->firstOrFail();
    app(VaulterDatabase::class)->write(fn () => $revision->forceFill(['scan_status' => 'pending'])->save());
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    app(ScanPolicyResolver::class)->initialize($drive->model(), OperationContext::system($drive->model(), 'share availability fixture'));
    ShareTestHelpers::share($node, token: 'pending-hook');
    $storage = Mockery::mock(StorageDriver::class);
    $storage->shouldNotReceive('get');
    app()->instance(StorageDriver::class, $storage);
    $this->getJson('api/vaulter/share/pending-hook')->assertOk();
    $this->get('api/vaulter/share/pending-hook/download')->assertStatus(423);
});
