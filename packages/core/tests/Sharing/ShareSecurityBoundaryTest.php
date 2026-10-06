<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\Permission;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Facades\Vaulter;
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
    AccessTestHelpers::grant($node, GrantLevel::Owner);
    $share = ShareTestHelpers::share($other);
    $forged = clone $share;
    $forged->node_id = $node->id;
    $operation = app()->makeWith(ShareOperations::class, ['context' => new OperationContext($drive, Actor::user(AccessTestHelpers::user()), 'foreign-test')]);
    expect(fn () => $operation->revoke($node, $forged))->toThrow(NodeNotFoundException::class)
        ->and($share->fresh()->revoked_at)->toBeNull();
});

it('rejects invalid direct share inputs before minting or recording', function (): void {
    $drive = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($drive, null, 'validation');
    $operation = app()->makeWith(ShareOperations::class, ['context' => OperationContext::system($drive, 'fixture')]);
    foreach ([new ShareLinkInput(1, GrantLevel::Commenter), new ShareLinkInput(Permission::Comment->value), new ShareLinkInput(64), new ShareLinkInput(-1), new ShareLinkInput(1, maxUses: 0), new ShareLinkInput(1, expiresAt: now()->subSecond()), new ShareLinkInput(1, expiresAt: now()->addYears(10))] as $input) {
        expect(fn () => $operation->create($node, $input))->toThrow(InvalidArgumentException::class);
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
    $minted = $operation->create($node, new ShareLinkInput(1));
    expect(app(ShareResolver::class)->resolve($minted->token)->isOk())->toBeTrue();
    if (DB::connection()->getDriverName() === 'pgsql') {
        $marker = sys_get_temp_dir().'/share-commit-'.bin2hex(random_bytes(8));
        try {
            $outcomes = ForkRace::run([
                function () use ($drive, $node, $minted, $marker): array {
                    app()->makeWith(ShareOperations::class, ['context' => OperationContext::system($drive, 'race revoke')])->revoke($node, $minted->share);
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
            expect($outcomes[0]->ok)->toBeTrue()->and($outcomes[1]->ok)->toBeTrue()
                ->and($outcomes[0]->value['backend'])->not->toBe($outcomes[1]->value['backend'])
                ->and($outcomes[1]->value['visible'])->toBeFalse();
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    } else {
        $operation->revoke($node, $minted->share);
    }
    expect(app(ShareResolver::class)->resolve($minted->token)->isOk())->toBeFalse();
});

it('checks the public download availability hook before opening bytes and keeps metadata visible', function (): void {
    Storage::fake('local');
    $drive = Vaulter::for(DriveTestHelpers::host());
    $node = $drive->upload(DriveTestHelpers::streamFor('quarantined'), originalName: 'sample.txt');
    $revision = $node->currentVersion()->firstOrFail();
    $revision->forceFill(['scan_status' => 'pending'])->save();
    ShareTestHelpers::share($node, token: 'pending-hook');
    $storage = Mockery::mock(StorageDriver::class);
    $storage->shouldNotReceive('get');
    app()->instance(StorageDriver::class, $storage);
    $this->getJson('api/vaulter/share/pending-hook')->assertOk();
    $this->get('api/vaulter/share/pending-hook/download')->assertStatus(423);
});
