<?php

declare(strict_types=1);

namespace Vaulter\Drive\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Packman\Attributes\Internal;
use Throwable;
use Vaulter\Configuration\TenancyOptions;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Content\ActivateScanPolicyAction;
use Vaulter\Drive\Actions\Content\RequestScanWorkAction;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Configuration\DriveOptionsValidator;
use Vaulter\Drive\Content\ScanPolicyResolver;
use Vaulter\Drive\Content\ScanPolicySpec;
use Vaulter\Drive\Content\ScanSnapshot;
use Vaulter\Drive\Content\ScanWorkReconciler;
use Vaulter\Drive\Enums\ScanWorkState;
use Vaulter\Drive\Models\Content\ScanObligation;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Repositories\Content\ScanEvidenceRepository;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Kernel;
use Vaulter\Media\Contracts\Cas\ContentScanner;
use Vaulter\Tenancy\Tenancy;

#[Internal]
final class RescanFileRevisionsCommand extends Command
{
    use OpensWriteScope;

    private bool $withinMaintenanceScope = false;

    protected $signature = 'vaulter:scan:rescan {--tenant=} {--drive=} {--all-tenants} {--reason=} {--revision=*} {--after=} {--limit=100} {--request-id=} {--rescan} {--retry-failed} {--activate-policy} {--dry-run} {--json}';

    protected $description = 'Activate scan policy or record bounded durable scan requests.';

    /** @return array{tenant:?string,drive:?string,all:bool,reason:string,limit:int} */
    public static function scope(Command $command): array
    {
        $tenant = self::stringOption($command, 'tenant');
        $drive = self::stringOption($command, 'drive');
        $all = $command->option('all-tenants');
        $reason = self::stringOption($command, 'reason');
        $limit = filter_var($command->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000 || ($all && $tenant !== null)
            || ($tenant !== null && trim($tenant) === '') || ($drive !== null && trim($drive) === '')) {
            throw new InvalidArgumentException('Invalid maintenance scope or limit.');
        }
        if (app(TenancyOptions::class)->enabled && $tenant === null && !$all) {
            throw new InvalidArgumentException('Tenant scope is required.');
        }
        if ($reason === null && !$all && $command->getName() === 'vaulter:scan:reconcile') {
            $reason = 'vaulter:scan:reconcile';
        }
        if (!$command->option('dry-run') && (!is_string($reason) || trim($reason) === '' || strlen($reason) > 191)) {
            throw new InvalidArgumentException('A nonempty maintenance reason is required.');
        }

        return ['tenant' => $tenant, 'drive' => $drive, 'all' => $all, 'reason' => $reason ?? 'maintenance dry run', 'limit' => $limit];
    }

    /** @param array{tenant:?string,drive:?string,all:bool,reason:string,limit:int} $scope
     * @return Builder<Drive>
     */
    public static function drives(array $scope): Builder
    {
        $class = app(DriveOptions::class)->model('drive');
        $query = app(TenancyBoundary::class)->constrain($class::query());
        if ($scope['tenant'] !== null) {
            if (app(TenancyBoundary::class)->enforced() && $scope['tenant'] !== app(TenancyBoundary::class)->current()) {
                throw new InvalidArgumentException('Selected tenant differs from current context.');
            }
            $query->where('tenant_id', $scope['tenant']);
        }
        if ($scope['drive'] !== null) {
            $query->whereKey($scope['drive']);
            if (!(clone $query)->exists()) {
                throw new InvalidArgumentException('Selected drive is outside the maintenance scope.');
            }
        }

        return $query->orderBy('id');
    }

    /** @return array{version:int,command:string,request_id:?string,scanned:int,activated:int,created:int,reused:int,skipped:int,failed:int,published:int,cleanup_completed:int,next_cursor:?string,elapsed_ms:int,dry_run:bool} */
    public static function summary(string $command, bool $dryRun): array
    {
        return ['version' => 1, 'command' => $command, 'request_id' => null, 'scanned' => 0,
            'activated' => 0, 'created' => 0, 'reused' => 0, 'skipped' => 0, 'failed' => 0,
            'published' => 0, 'cleanup_completed' => 0, 'next_cursor' => null, 'elapsed_ms' => 0,
            'dry_run' => $dryRun];
    }

    public function handle(VaulterDatabase $database, ScanPolicyResolver $policies, ActivateScanPolicyAction $activate,
        RequestScanWorkAction $requestWork, ScanEvidenceRepository $evidence, ScanSnapshot $snapshots,
        ScanWorkReconciler $publisher, DriveOptionsValidator $validator): int
    {
        $started = microtime(true);
        try {
            $scope = self::scope($this);
            if ($scope['all'] && !$this->withinMaintenanceScope) {
                $this->withinMaintenanceScope = true;
                try {
                    return Tenancy::without(fn (): int => $this->handle($database, $policies, $activate, $requestWork, $evidence, $snapshots, $publisher, $validator));
                } finally {
                    $this->withinMaintenanceScope = false;
                }
            }
            $activating = $this->option('activate-policy');
            if (($this->option('rescan') && $this->option('retry-failed'))
                || ($activating && ($this->option('rescan') || $this->option('retry-failed') || $this->revisionKeys() !== []))) {
                throw new InvalidArgumentException('Contradictory scan flags.');
            }
            if (!$activating) {
                if (!app(Kernel::class)->capabilities()->has(ContentScanner::class)) {
                    throw new InvalidArgumentException('Rescan requires a configured scanner.');
                }
                $validator->validateScanWork(app(DriveOptions::class), requireBroker: true);
            }
            $request = self::stringOption($this, 'request-id') ?? (string) Str::uuid();
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $request) !== 1) {
                throw new InvalidArgumentException('Request ID must be a UUIDv4.');
            }
            $request = strtolower($request);
            $base = ['version' => 1, 'request_id' => $request, 'mode' => $activating ? 'activate' : ($this->option('rescan') ? 'rescan' : ($this->option('retry-failed') ? 'retry' : 'ensure')),
                'tenant' => $scope['tenant'], 'drive' => $scope['drive'], 'all_tenants' => $scope['all'], 'filters' => $this->revisionKeys(), 'dry_run' => $this->option('dry-run'), 'spec_keys' => []];
            $drives = self::drives($scope);
            foreach (app(ProfileRegistry::class)->all() as $profile) {
                $base['spec_keys'][$profile->getKey()] = array_map(fn (ScanPolicySpec $spec): string => $spec->configFingerprint(),
                    app(ProfilePolicy::class)->scanSpecs($profile));
            }
            $cursor = null;
            if (self::stringOption($this, 'after') !== null) {
                if ($this->option('request-id') === null) {
                    throw new InvalidArgumentException('Cursor resume requires the original request ID.');
                }
                $cursor = $this->decodeCursor(self::stringOption($this, 'after'));
                foreach ($base as $key => $value) {
                    if (($cursor[$key] ?? null) !== $value) {
                        throw new InvalidArgumentException('Cursor scope or policy changed.');
                    }
                }
                $drives->where('id', $activating || $cursor['last_revision_id'] === null ? '>' : '>=', $cursor['last_drive_id']);
            }
            $revisionClass = app(DriveOptions::class)->model('file_revision');
            // Validate every explicit key before any mutation; keys never derive tenant privilege.
            foreach ($this->revisionKeys() as $key) {
                if (!$revisionClass::query()->whereKey($key)->whereHas('file.node', fn (Builder $q): Builder => $q->whereIn('drive_id', self::drives($scope)->select('id')))->exists()) {
                    throw new InvalidArgumentException('Selected revision is outside the maintenance scope.');
                }
            }
            $out = self::summary('vaulter:scan:rescan', $this->option('dry-run'));
            $out['request_id'] = $request;
            $last = null;
            foreach ($drives->limit($scope['limit'] + 1)->get() as $drive) {
                if ($out['scanned'] >= $scope['limit']) {
                    break;
                }
                if ($activating) {
                    $out['scanned']++;
                    if (!$out['dry_run']) {
                        $database->transaction(function () use ($database, $drive, $policies, $activate, $scope): void {
                            $database->lockAggregate('drives', $drive->id);
                            $fresh = $drive->newQuery()->whereKey($drive->id)->lockForUpdate()->firstOrFail();
                            $context = DriveContext::system($fresh, $scope['reason']);
                            foreach ($policies->desired($fresh) as $spec) {
                                $activate->execute($fresh, $spec, $spec->rolloutKey, $context);
                            }
                        });
                    }
                    $out['activated']++;
                    $last = ['last_drive_id' => $drive->id, 'last_revision_id' => null, 'last_admission_sequence' => 0];

                    continue;
                }
                $last = ['last_drive_id' => $drive->id, 'last_revision_id' => null, 'last_admission_sequence' => 0];
                $specs = $policies->forDrive($drive);
                $revisions = $revisionClass::query()->whereHas('file.node', fn (Builder $q): Builder => $q->where('drive_id', $drive->id));
                if ($this->revisionKeys() !== []) {
                    $revisions->whereKey($this->revisionKeys());
                }
                if ($cursor !== null && $cursor['last_revision_id'] !== null && $drive->id === $cursor['last_drive_id']) {
                    $revisions->where('scan_admission_sequence', '>=', $cursor['last_admission_sequence'])
                        ->where(fn (Builder $q): Builder => $q->where('scan_admission_sequence', '>', $cursor['last_admission_sequence'])
                            ->orWhere(fn (Builder $q): Builder => $q->where('scan_admission_sequence', $cursor['last_admission_sequence'])->where('id', '>', $cursor['last_revision_id'])));
                }
                foreach ($revisions->orderBy('scan_admission_sequence')->orderBy('id')->limit($scope['limit'] - $out['scanned'])->get() as $revision) {
                    $out['scanned']++;
                    $last = ['last_drive_id' => $drive->id, 'last_revision_id' => $revision->id, 'last_admission_sequence' => $revision->scan_admission_sequence];
                    foreach ($specs as $spec) {
                        $existing = $revision->scanObligations()->where('spec_key', $spec->specKey())->first();
                        if (!$this->option('rescan') && ($evidence->compatible($revision, $spec) !== null
                            || ($existing?->state === ScanWorkState::Settled && !$this->option('retry-failed')))) {
                            $out['skipped']++;

                            continue;
                        }
                        if ($out['dry_run']) {
                            $out[$existing === null ? 'created' : 'reused']++;

                            continue;
                        }
                        $obligation = $database->transaction(function () use ($database, $drive, $revision, $snapshots, $policies, $requestWork, $spec, $request, $scope, $existing, $publisher): ScanObligation {
                            $database->lockAggregateShared('drives', $drive->id);
                            $snapshot = $snapshots->load($revision, true);
                            $policies->forDrive($snapshot['drive']);
                            $context = DriveContext::system($snapshot['drive'], $scope['reason']);
                            $result = $this->option('rescan') || ($this->option('retry-failed') && $existing?->outcome === 'failed')
                                ? $requestWork->requestRescan($snapshot['revision'], $spec, $request, $context)
                                : $requestWork->ensure($snapshot['revision'], $spec, $request, $context);
                            if ($result->state !== ScanWorkState::Settled) {
                                $publisher->afterCommit($result);
                            }

                            return $result;
                        });
                        $out[$existing === null ? 'created' : 'reused']++;
                        $out['failed'] += $obligation->outcome === 'failed' ? 1 : 0;
                    }
                }
            }
            if ($last !== null && ($out['scanned'] === $scope['limit']
                || self::drives($scope)->where('id', '>', $last['last_drive_id'])->exists())) {
                $out['next_cursor'] = $this->encodeCursor([...$base, ...$last]);
            }
            $out['elapsed_ms'] = (int) ((microtime(true) - $started) * 1000);
            $this->line(json_encode($out, JSON_THROW_ON_ERROR));

            return 0;
        } catch (InvalidConfigurationException $exception) {
            $this->error('Invalid scan queue configuration: '.$exception->getMessage());

            return 2;
        } catch (InvalidArgumentException) {
            $this->error('Invalid scan maintenance scope, flags, scanner or cursor.');

            return 2;
        } catch (Throwable) {
            $this->error('Scan maintenance could not complete safely.');

            return 1;
        }
    }

    public static function stringOption(Command $command, string $name): ?string
    {
        $value = $command->option($name);
        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException('Invalid command option.');
        }

        return $value;
    }

    /** @return list<string> */
    private function revisionKeys(): array
    {
        $keys = $this->option('revision');
        if (!array_is_list($keys) || !array_all($keys, static fn (mixed $key): bool => is_string($key) && trim($key) !== '')) {
            throw new InvalidArgumentException('Invalid revision selection.');
        }
        $validated = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Invalid revision key.');
            }
            $validated[] = $key;
        }

        return $validated;
    }

    /** @param array<string,mixed> $payload */
    private function encodeCursor(array $payload): string
    {
        $key = app(Repository::class)->get('app.key');
        if (!is_string($key) || $key === '') {
            throw new InvalidArgumentException('Maintenance cursor signing key is unavailable.');
        }
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=').'.'.hash_hmac('sha256', $json, $key);
    }

    /** @return array<string,mixed> */
    private function decodeCursor(string $cursor): array
    {
        $key = app(Repository::class)->get('app.key');
        if (!is_string($key) || $key === '') {
            throw new InvalidArgumentException('Maintenance cursor signing key is unavailable.');
        }
        $parts = explode('.', $cursor);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('Invalid cursor envelope.');
        }
        [$encoded, $signature] = $parts;
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($json === false || !hash_equals(hash_hmac('sha256', $json, $key), $signature)) {
            throw new InvalidArgumentException('Invalid cursor signature.');
        }
        $payload = json_decode($json, true);
        if (!is_array($payload) || !is_int($payload['last_admission_sequence'] ?? null)
            || (!is_int($payload['last_drive_id'] ?? null) && !is_string($payload['last_drive_id'] ?? null))
            || (!is_int($payload['last_revision_id'] ?? null) && !is_string($payload['last_revision_id'] ?? null) && ($payload['last_revision_id'] ?? null) !== null)) {
            throw new InvalidArgumentException('Invalid cursor payload.');
        }
        $validated = [];
        foreach ($payload as $field => $value) {
            if (!is_string($field)) {
                throw new InvalidArgumentException('Invalid cursor fields.');
            }
            $validated[$field] = $value;
        }

        return $validated;
    }
}
