<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing\Contracts;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Packman\Attributes\Api;
use PHPUnit\Framework\Assert;
use Vaulter\Configuration\TenancyOptions;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Actions\Content\ClaimScanWorkAction;
use Vaulter\Drive\Actions\Content\SettleScanWorkAction;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Configuration\ScanOptions;
use Vaulter\Drive\Content\ScanPolicyResolver;
use Vaulter\Drive\Content\ScanWorkReconciler;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Database\Factories\PinnedTenantResolver;
use Vaulter\Drive\Enums\ScanStatus;
use Vaulter\Drive\Enums\ScanWorkState;
use Vaulter\Drive\Exceptions\Files\ContentUnavailableException;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Jobs\Uploads\ScanFileRevision;
use Vaulter\Drive\Models\Content\ScanObligation;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\InteractsWithVaulter;
use Vaulter\Media\Content\ScanResult;
use Vaulter\Media\Contracts\Cas\ContentScanner;
use Vaulter\Media\Enums\Cas\ScanMode;
use Vaulter\Media\Enums\Cas\ScanVerdict;
use Vaulter\Media\Testing\FakeContentScanner;
use Vaulter\Testing\Fixtures\FixtureOwner;

/**
 * The scan lifecycle as the host has configured it, proved through the public surface:
 * drive Operations as the given actor, the vaulter:scan:reconcile command and a scripted
 * scanner. The host's configuration is applied by $configureHost; the scan mode, the
 * scanner identity and the queue come from it.
 *
 * ```php
 * (new ScanLifecycleContractTests)->assertScanLifecycleContract(
 *     static fn () => Config::set('vaulter-drive.scan.mode', 'all_content'),
 *     $user,
 * );
 * ```
 *
 * It proves four things: (a) scan work is created in the scope of the drive and tenant
 * of the revision, and evidence is never reused across scopes; (b) the mode keeps its
 * egress boundary: new content waits for a clean verdict, and legacy content is blocked
 * only when the mode is all_content; (c) a lost publication and an expired lease are
 * recovered by the command, and a stale attempt cannot settle; (d) switching webhooks
 * off leaves scanning on.
 *
 * Bytes go through the real Operations, so the host needs a database, the fake disks it
 * configures, an asynchronous queue connection for the command, and an actor that may
 * create and read in a drive (the suite grants it the owner role on the drives it makes).
 */
#[Api]
final class ScanLifecycleContractTests
{
    private FakeContentScanner $scanner;

    private int $processed = 0;

    /** @param callable(): mixed $configureHost */
    public function assertScanLifecycleContract(callable $configureHost, Authenticatable $actor): void
    {
        if (!$actor instanceof Model || !$actor instanceof Authorizable || (!is_int($actor->getKey()) && !is_string($actor->getKey()))) {
            Assert::fail('The actor must be an Eloquent model that is Authorizable and has a scalar key: its morph reference identifies it in grants.');
        }

        $configureHost();
        $spec = App::make(ScanOptions::class)->globalSpec();
        Assert::assertNotSame(ScanMode::Off, $spec->mode, 'Scanning is off in the host configuration, so there is no lifecycle to prove.');

        $this->scanner = new FakeContentScanner($spec->scannerKey, $spec->scannerVersion);
        // The scanner is the capability the host names in config: the class, and the very object behind it.
        Config::set('vaulter-drive.scan.scanner', FakeContentScanner::class);
        App::instance(FakeContentScanner::class, $this->scanner);
        App::instance(ContentScanner::class, $this->scanner);
        (new class
        {
            use InteractsWithVaulter;
        })->vaulterFakeStorage();
        Bus::fake();
        App::forgetInstance(ScanWorkReconciler::class);
        $this->processed = 0;

        $this->assertScopeIsolation($actor);
        $this->assertPolicyBoundary($actor, $spec->mode);
        $this->assertFaultRecovery($actor);
        $this->assertWebhooksDoNotSwitchScanning($actor);
    }

    /** (a) Work lives in the scope of its revision, and evidence is never reused across scopes. */
    private function assertScopeIsolation(Model&Authorizable $actor): void
    {
        $tenantA = $this->currentTenant();
        $tenantB = $tenantA === null ? null : $this->otherTenant($tenantA);
        $bytes = 'scope proof '.bin2hex(random_bytes(4));

        [$driveA, $nodeA] = $this->within($tenantA, fn (): array => $this->uploaded($actor, $bytes));
        $this->within($tenantA, fn () => $this->assertWorkInScopeOf($driveA, $nodeA));
        $this->settle();
        $this->within($tenantA, fn () => Assert::assertSame(ScanStatus::Clean->value, $this->status($nodeA), 'The first revision must settle clean.'));
        $scansAfterA = count($this->scanner->scans);

        // The same bytes under another scope must be scanned on their own: A's clean evidence is not B's.
        [$driveB, $nodeB] = $this->within($tenantB, fn (): array => $this->uploaded($actor, $bytes));
        $this->within($tenantB, function () use ($driveB, $nodeB): void {
            $this->assertWorkInScopeOf($driveB, $nodeB);
            Assert::assertNotSame(ScanStatus::Clean->value, $this->status($nodeB), 'Evidence of another scope must not make this revision clean.');
            Assert::assertSame(0, $this->revision($nodeB)->scanEvidence()->count(), 'No evidence may be reused across scopes.');
        });
        if ($tenantA !== null) {
            // A drive handle only opens under its own tenant, so each is read under it.
            Assert::assertNotSame(
                $this->within($tenantA, fn (): string => (string) $driveA->model()->tenant_id),
                $this->within($tenantB, fn (): string => (string) $driveB->model()->tenant_id),
                'The two drives must belong to different tenants.',
            );
        }

        $this->settle();
        $this->within($tenantB, function () use ($nodeB, $scansAfterA): void {
            Assert::assertGreaterThan($scansAfterA, count($this->scanner->scans), 'The revision of the other scope must be scanned itself.');
            Assert::assertSame(ScanStatus::Clean->value, $this->status($nodeB));
        });
    }

    private function assertWorkInScopeOf(DriveHandle $drive, NodeSnapshot $node): void
    {
        $work = $this->obligations($node);
        Assert::assertNotEmpty($work, 'An upload that the policy requires to scan must create scan work.');
        foreach ($work as $row) {
            Assert::assertSame((string) $drive->model()->id, (string) $row->drive_id, 'Scan work must belong to the drive of its revision.');
            Assert::assertSame($this->normalize($drive->model()->tenant_id), $this->normalize($row->tenant_id), 'Scan work must carry the tenant of its revision.');
        }
    }

    /** (b) New content waits for a clean verdict; legacy content is blocked only under all_content. */
    private function assertPolicyBoundary(Model&Authorizable $actor, ScanMode $mode): void
    {
        [$drive, $node] = $this->uploaded($actor, 'policy proof '.bin2hex(random_bytes(4)));
        Assert::assertFalse($this->readable($drive, $node), 'New content must not be readable before it is clean.');
        $this->settle();
        Assert::assertTrue($this->readable($drive, $node), 'New content must be readable once it is clean.');

        $previous = Config::get('vaulter-drive.scan');
        Config::set('vaulter-drive.scan.mode', ScanMode::Off->value);
        try {
            [$legacyDrive, $legacy] = $this->uploaded($actor, 'legacy proof '.bin2hex(random_bytes(4)));
        } finally {
            Config::set('vaulter-drive.scan', $previous);
        }
        Assert::assertSame(ScanStatus::NotRequired->value, $this->status($legacy), 'Legacy content could not be created: the suite switches vaulter-drive.scan.mode off for it, so the host must read the mode from there.');

        $model = $legacyDrive->model();
        App::make(VaulterDatabase::class)->write(static fn () => App::make(ScanPolicyResolver::class)->initialize($model, DriveContext::system($model, 'scan lifecycle contract')));

        if ($mode === ScanMode::AllContent) {
            Assert::assertFalse($this->readable($legacyDrive, $legacy), 'Under all_content, legacy content must stay unreadable until it is clean.');
            for ($round = 0; $round < 6 && !$this->readable($legacyDrive, $legacy); $round++) {
                $this->settle($legacyDrive);
            }
            Assert::assertTrue($this->readable($legacyDrive, $legacy), 'Under all_content, legacy content must become readable once the backfill has scanned it.');

            return;
        }

        Assert::assertTrue($this->readable($legacyDrive, $legacy), 'Under new_content_only, legacy content keeps the boundary and stays readable.');
        $this->settle($legacyDrive);
        Assert::assertTrue($this->readable($legacyDrive, $legacy));
    }

    /** (c) A lost publication and an expired lease are recovered; a stale attempt cannot settle. */
    private function assertFaultRecovery(Model&Authorizable $actor): void
    {
        [$drive, $node] = $this->uploaded($actor, 'fault proof '.bin2hex(random_bytes(4)));
        $work = $this->obligations($node)[0];
        $scansBefore = count($this->scanner->scans);

        // A publication that never reached the queue: the durable work is still due.
        $this->force($work, ['last_published_at' => null, 'available_at' => now('UTC')->subSecond()]);
        $dispatched = Bus::dispatched(ScanFileRevision::class)->count();
        $this->reconcile($drive);
        Assert::assertGreaterThan($dispatched, Bus::dispatched(ScanFileRevision::class)->count(), 'The reconcile command must republish scan work whose publication was lost.');

        // A worker that died holding the lease: the command hands the work back.
        $stale = App::make(ClaimScanWorkAction::class)->execute($work->id, $work->generation, new DateTimeImmutable('now'));
        Assert::assertNotNull($stale, 'Due scan work must be claimable.');
        $this->force($work->refresh(), ['lease_expires_at' => now('UTC')->subSecond(), 'last_published_at' => null]);
        $this->reconcile($drive);
        Assert::assertSame(ScanWorkState::RetryWait, $work->refresh()->state, 'The reconcile command must recover an expired lease.');
        Assert::assertNull($work->lease_token, 'A recovered lease must not keep its token.');

        // The dead worker wakes up late: its attempt no longer owns the work.
        Assert::assertFalse(App::make(SettleScanWorkAction::class)->execute($stale, new ScanResult(ScanVerdict::Clean)), 'A stale attempt must not settle scan work.');
        Assert::assertNotSame(ScanStatus::Clean->value, $this->status($node), 'A stale attempt must not make the revision clean.');

        $this->settle($drive);
        Assert::assertSame(ScanStatus::Clean->value, $this->status($node), 'The recovered work must settle with a fresh attempt.');
        Assert::assertSame($scansBefore + 1, count($this->scanner->scans), 'Recovery scans the bytes exactly once.');
    }

    /**
     * (d) Webhooks are an unrelated, opt-in module: scanning works whether the host activated it or not
     * (a default installation has it off).
     */
    private function assertWebhooksDoNotSwitchScanning(Model&Authorizable $actor): void
    {
        [$drive, $node] = $this->uploaded($actor, 'webhooks proof '.bin2hex(random_bytes(4)));
        Assert::assertNotEmpty($this->obligations($node), 'Whatever the webhooks module, uploads must create scan work.');
        Assert::assertFalse($this->readable($drive, $node), 'Whatever the webhooks module, new content must wait for its verdict.');
        $this->settle();
        Assert::assertTrue($this->readable($drive, $node), 'Whatever the webhooks module, scanning must settle the work.');
    }

    /** @return array{DriveHandle, NodeSnapshot} */
    private function uploaded(Model&Authorizable $actor, string $bytes): array
    {
        $key = $actor->getKey();
        if (!is_int($key) && !is_string($key)) {
            Assert::fail('The actor needs a scalar key.');
        }
        $system = Vaulter::drive(FixtureOwner::next())->asSystem('scan lifecycle contract');
        $system->access()->grant($system->root()->id, new GrantInput(new Subject($actor->getMorphClass(), $key), RoleRegistry::OWNER));
        $drive = $system->actingAs($actor);

        return [$drive, $drive->files()->upload(UploadSource::fromString($bytes, 'contract.txt'))];
    }

    private function revision(NodeSnapshot $node): FileRevision
    {
        $class = App::make(DriveOptions::class)->model('node');

        return $class::query()->findOrFail($node->id)->file()->firstOrFail()->currentRevision()->firstOrFail();
    }

    private function status(NodeSnapshot $node): string
    {
        return $this->revision($node)->scan_status;
    }

    /** @return list<ScanObligation> */
    private function obligations(NodeSnapshot $node): array
    {
        return array_values($this->revision($node)->scanObligations()->get()->all());
    }

    private function readable(DriveHandle $drive, NodeSnapshot $node): bool
    {
        try {
            $stream = $drive->files()->read($node);
        } catch (ContentUnavailableException) {
            return false;
        }
        if (is_resource($stream->stream)) {
            fclose($stream->stream);
        }

        return true;
    }

    /** Publish what is due (optionally for one drive), then run the queued scan jobs the way a worker would. */
    private function settle(?DriveHandle $drive = null): void
    {
        if ($drive !== null) {
            $this->reconcile($drive);
        }
        $jobs = Bus::dispatched(ScanFileRevision::class)->values();
        foreach ($jobs->slice($this->processed) as $job) {
            if ($job instanceof ScanFileRevision) {
                $this->within($job->tenantId === null ? null : (string) $job->tenantId, static fn (): mixed => App::call($job->handle(...)));
            }
        }
        $this->processed = $jobs->count();
    }

    private function reconcile(DriveHandle $drive): void
    {
        $model = $drive->model();
        $options = ['--drive' => (string) $model->id, '--json' => true];
        if (App::make(TenancyOptions::class)->enabled) {
            $options['--tenant'] = (string) $model->tenant_id;
        }
        $exit = $this->within($model->tenant_id === null ? null : (string) $model->tenant_id, static fn (): int => Artisan::call('vaulter:scan:reconcile', $options));
        Assert::assertSame(0, $exit, 'vaulter:scan:reconcile failed: '.Artisan::output());
    }

    /** @param array<string, mixed> $attributes */
    private function force(ScanObligation $work, array $attributes): void
    {
        App::make(VaulterDatabase::class)->write(static fn () => $work->forceFill($attributes)->save());
    }

    /** Another tenant id of the same shape as $tenant: the tenant column's type is the host's choice. */
    private function otherTenant(string $tenant): string
    {
        return match (true) {
            ctype_digit($tenant) => (string) (((int) $tenant) + 1),
            Str::isUuid($tenant) => (string) Str::uuid(),
            Str::isUlid($tenant) => (string) Str::ulid(),
            default => $tenant.'-other',
        };
    }

    private function currentTenant(): ?string
    {
        if (!App::make(TenancyOptions::class)->enabled) {
            return null;
        }
        $tenant = App::make(TenantResolver::class)->current();
        Assert::assertNotNull($tenant, 'Strict tenancy is on, so the suite needs a current tenant.');

        return (string) $tenant;
    }

    private function normalize(int|string|null $tenant): ?string
    {
        return $tenant === null ? null : (string) $tenant;
    }

    /**
     * @template T
     *
     * @param  Closure(): T $callback
     * @return T
     */
    private function within(?string $tenant, Closure $callback): mixed
    {
        if ($tenant === null || !App::make(TenancyOptions::class)->enabled) {
            return $callback();
        }
        $previous = App::make(TenantResolver::class);
        App::instance(TenantResolver::class, PinnedTenantResolver::over($tenant, $previous));
        try {
            return $callback();
        } finally {
            App::instance(TenantResolver::class, $previous);
        }
    }
}
