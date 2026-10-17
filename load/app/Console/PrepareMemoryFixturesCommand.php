<?php

declare(strict_types=1);

namespace Vaulter\Load\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Vaulter\Drive\Actions\Uploads\CompleteUploadAction;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;
use Vaulter\Drive\Support\Uploads\UploadModels;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Uploads\S3MultipartHandler;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Facades\Vaulter;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * P4.2 — bounded on-disk fixtures + API handles for the memory path matrix.
 */
final class PrepareMemoryFixturesCommand extends Command
{
    protected $signature = 'load:memory-fixtures
        {action : prepare|cleanup|cleanup-api-state|probe-object|show}
        {--fixture-id= : Stable fixture id (idempotent key)}
        {--run-prefix= : Run-scoped cleanup prefix}
        {--size=5MiB : Payload size (5MiB|500MiB|5GiB)}
        {--with-api-state : Also seed tus offset, S3 parts-only session, and download node}
        {--matrix-path=all : Limit API seeding to one matrix path (tus_final_patch|s3_putpart|s3_complete_postfix|download|cas_stage_probe|all)}
        {--actor=1 : X-Load-Actor id for storage bootstrap}';

    protected $description = 'Prepare or clean bounded P4 memory path fixtures on disk (streaming writes only).';

    public function handle(
        S3MultipartHandler $s3,
        UploadSessionManager $sessions,
        UploadSessionStoreRepository $sessionStore,
        StagedUpload $stagedUpload,
        CompleteUploadAction $commit,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'prepare' => $this->prepare($s3, $sessions, $sessionStore, $stagedUpload, $commit),
            'cleanup' => $this->cleanup(),
            'cleanup-api-state' => $this->cleanupApiState($sessions, $stagedUpload),
            'probe-object' => $this->probeObject(),
            'show' => $this->show(),
            default => $this->invalidAction($action),
        };
    }

    private function prepare(
        S3MultipartHandler $s3,
        UploadSessionManager $sessions,
        UploadSessionStoreRepository $sessionStore,
        StagedUpload $stagedUpload,
        CompleteUploadAction $commit,
    ): int {
        $fixtureId = (string) $this->option('fixture-id');
        $runPrefix = (string) $this->option('run-prefix');

        if ($fixtureId === '' || $runPrefix === '') {
            $this->components->error('--fixture-id and --run-prefix are required for prepare.');

            return self::FAILURE;
        }

        $sizeBytes = MemoryFixtureSupport::parseSize((string) $this->option('size'));
        $directory = MemoryFixtureSupport::fixtureDirectory($runPrefix, $fixtureId);
        $manifestPath = $directory.'/manifest.json';
        $payloadPath = $directory.'/payload.bin';

        if (is_file($manifestPath)) {
            $existing = MemoryFixtureSupport::readJsonFile($manifestPath);

            if (
                is_array($existing)
                && ($existing['fixture_id'] ?? null) === $fixtureId
                && (int) ($existing['size_bytes'] ?? 0) === $sizeBytes
            ) {
                if ($this->option('with-api-state')) {
                    $payloadPath = (string) ($existing['payload_path'] ?? $payloadPath);
                    $matrixPath = (string) $this->option('matrix-path');
                    $seeded = $this->seedApiState(
                        s3: $s3,
                        sessions: $sessions,
                        sessionStore: $sessionStore,
                        stagedUpload: $stagedUpload,
                        commit: $commit,
                        fixtureId: $fixtureId,
                        sizeBytes: $sizeBytes,
                        payloadPath: $payloadPath,
                        matrixPath: $matrixPath,
                    );
                    $existing['paths'] = array_merge($existing['paths'] ?? [], $seeded);
                    file_put_contents(
                        $manifestPath,
                        json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
                    );
                    $this->components->info("Fixture [{$fixtureId}] payload reused — API state refreshed.");
                } else {
                    $this->components->info("Fixture [{$fixtureId}] already prepared — manifest reused.");
                }

                return self::SUCCESS;
            }
        }

        File::ensureDirectoryExists($directory);

        $payload = MemoryFixtureSupport::writePayloadFile($payloadPath, $sizeBytes, $fixtureId);
        $probeSha = MemoryFixtureSupport::hashFileBounded($payloadPath);

        if ($probeSha !== $payload['sha256']) {
            throw new RuntimeException('Bounded object probe checksum mismatch after write.');
        }

        $manifest = [
            'schema_version' => MemoryFixtureSupport::SCHEMA_VERSION,
            'fixture_id' => $fixtureId,
            'run_prefix' => $runPrefix,
            'size_bytes' => $sizeBytes,
            'sha256' => $payload['sha256'],
            'payload_path' => $payloadPath,
            'probe_object_sha256' => $probeSha,
            'paths' => [],
        ];

        if ($this->option('with-api-state')) {
            $manifest['paths'] = $this->seedApiState(
                s3: $s3,
                sessions: $sessions,
                sessionStore: $sessionStore,
                stagedUpload: $stagedUpload,
                commit: $commit,
                fixtureId: $fixtureId,
                sizeBytes: $sizeBytes,
                payloadPath: $payloadPath,
                matrixPath: (string) $this->option('matrix-path'),
            );
        }

        file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );

        $this->components->info("Prepared fixture [{$fixtureId}] at {$directory} ({$sizeBytes} bytes).");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function seedApiState(
        S3MultipartHandler $s3,
        UploadSessionManager $sessions,
        UploadSessionStoreRepository $sessionStore,
        StagedUpload $stagedUpload,
        CompleteUploadAction $commit,
        string $fixtureId,
        int $sizeBytes,
        string $payloadPath,
        string $matrixPath = 'all',
    ): array {
        $actorId = (int) $this->option('actor');
        $host = DriveTestHelpers::host($actorId);
        $drive = Vaulter::for($host);
        $storage = $drive->model();
        $root = $drive->root();
        self::grantOwnerOnce($root, $actorId);

        $want = static fn (string $pathId): bool => $matrixPath === 'all' || $matrixPath === $pathId;

        /** @var array<string, mixed> $paths */
        $paths = [
            'storage_id' => $storage->getKey(),
            'cas_stage_probe' => [
                'payload_path' => $payloadPath,
            ],
        ];

        if ($want('tus_final_patch')) {
            $tusSession = $sessions->initiate(new InitiateUploadInput(
                storage: $storage,
                protocol: UploadProtocol::Tus,
                targetParent: $root,
                targetName: "p4-{$fixtureId}.bin",
                totalSizeBytes: $sizeBytes,
                mimeType: 'application/octet-stream',
            ));

            $tusOffset = max(0, $sizeBytes - min(8 * 1024 * 1024, $sizeBytes));
            $this->stageSessionFromFile(
                stagedUpload: $stagedUpload,
                sessionStore: $sessionStore,
                session: $tusSession,
                payloadPath: $payloadPath,
                sizeBytes: $sizeBytes,
                receivedBytes: $tusOffset,
            );
            $paths['tus_final_patch'] = [
                'upload_id' => $tusSession->upload_id,
                'offset_before_final' => $tusOffset,
                'final_chunk_bytes' => $sizeBytes - $tusOffset,
            ];
        }

        if ($want('s3_putpart') || $want('s3_complete_postfix')) {
            $partsSession = $sessions->initiate(new InitiateUploadInput(
                storage: $storage,
                protocol: UploadProtocol::S3Multipart,
                targetParent: $root,
                targetName: "p4-parts-{$fixtureId}.bin",
                totalSizeBytes: $sizeBytes,
                mimeType: 'application/octet-stream',
            ));

            $partBytes = min(64 * 1024 * 1024, $sizeBytes);
            $partNo = 1;
            $byteOffset = 0;
            $handle = fopen($payloadPath, 'rb');

            if ($handle === false) {
                throw new RuntimeException('Unable to open payload for S3 part staging.');
            }

            $stopOffset = $want('s3_putpart') && !$want('s3_complete_postfix')
                ? min($partBytes, $sizeBytes)
                : $sizeBytes;

            while ($byteOffset < $stopOffset) {
                $remaining = $sizeBytes - $byteOffset;
                $currentLen = (int) min($partBytes, $remaining, $stopOffset - $byteOffset);
                $chunk = fread($handle, $currentLen);

                if ($chunk === false || strlen($chunk) !== $currentLen) {
                    fclose($handle);
                    throw new RuntimeException('Truncated payload while staging S3 parts.');
                }

                $stream = fopen('php://memory', 'rb+');

                if ($stream === false) {
                    fclose($handle);
                    throw new RuntimeException('Unable to open memory stream for S3 part.');
                }

                fwrite($stream, $chunk);
                rewind($stream);
                $s3->putPart($partsSession->refresh(), $partNo, $byteOffset, $stream);
                fclose($stream);

                $byteOffset += $currentLen;
                $partNo++;
            }

            fclose($handle);

            if ($want('s3_putpart')) {
                $paths['s3_putpart'] = [
                    'upload_id' => $partsSession->upload_id,
                    'part_bytes' => $partBytes,
                ];
            }

            if ($want('s3_complete_postfix')) {
                $paths['s3_complete_postfix'] = [
                    'upload_id' => $partsSession->upload_id,
                    'parts_ready' => $partNo - 1,
                ];
            }
        }

        if ($want('download')) {
            $downloadNode = $this->commitDownloadFile(
                sessions: $sessions,
                sessionStore: $sessionStore,
                stagedUpload: $stagedUpload,
                commit: $commit,
                storage: $storage,
                root: $root,
                fixtureId: $fixtureId,
                sizeBytes: $sizeBytes,
                payloadPath: $payloadPath,
            );
            $paths['download'] = [
                'node_id' => $downloadNode->getKey(),
                'sha256' => MemoryFixtureSupport::hashFileBounded($payloadPath),
            ];
        }

        if (!$want('cas_stage_probe') && $matrixPath !== 'all') {
            unset($paths['cas_stage_probe']);
        }

        return $paths;
    }

    private function stageSessionFromFile(
        StagedUpload $stagedUpload,
        UploadSessionStoreRepository $sessionStore,
        UploadSession $session,
        string $payloadPath,
        int $sizeBytes,
        int $receivedBytes,
    ): void {
        $handle = fopen($payloadPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open payload for staged upload seeding.');
        }

        try {
            $stagedBytes = $stagedUpload->writeStream($session, $handle);
        } finally {
            fclose($handle);
        }

        if ($stagedBytes !== $sizeBytes) {
            throw new RuntimeException("Staged fixture size mismatch: expected {$sizeBytes}, got {$stagedBytes}.");
        }

        $sessionStore->advanceReceived($session, $receivedBytes);
    }

    private function commitDownloadFile(
        UploadSessionManager $sessions,
        UploadSessionStoreRepository $sessionStore,
        StagedUpload $stagedUpload,
        CompleteUploadAction $commit,
        Drive $storage,
        Node $root,
        string $fixtureId,
        int $sizeBytes,
        string $payloadPath,
    ): Node {
        $session = $sessions->initiate(new InitiateUploadInput(
            storage: $storage,
            protocol: UploadProtocol::Tus,
            targetParent: $root,
            targetName: "p4-download-{$fixtureId}.bin",
            totalSizeBytes: $sizeBytes,
            mimeType: 'application/octet-stream',
        ));

        $this->stageSessionFromFile(
            stagedUpload: $stagedUpload,
            sessionStore: $sessionStore,
            session: $session,
            payloadPath: $payloadPath,
            sizeBytes: $sizeBytes,
            receivedBytes: $sizeBytes,
        );
        $sessions->markAssembled($session->refresh());

        return $commit->execute($session->refresh());
    }

    private function cleanup(): int
    {
        $runPrefix = (string) $this->option('run-prefix');

        if ($runPrefix === '') {
            $this->components->error('--run-prefix is required for cleanup.');

            return self::FAILURE;
        }

        $target = MemoryFixtureSupport::fixturesRoot().'/'.MemoryFixtureSupport::sanitizeToken($runPrefix);

        if (is_dir($target)) {
            File::deleteDirectory($target);
            $this->components->info("Removed fixtures under [{$target}].");
        } else {
            $this->components->warn("No fixtures found for prefix [{$runPrefix}].");
        }

        return self::SUCCESS;
    }

    private function cleanupApiState(UploadSessionManager $sessions, StagedUpload $stagedUpload): int
    {
        $fixtureId = (string) $this->option('fixture-id');
        $runPrefix = (string) $this->option('run-prefix');

        if ($fixtureId === '' || $runPrefix === '') {
            $this->components->error('--fixture-id and --run-prefix are required for cleanup-api-state.');

            return self::FAILURE;
        }

        $manifestPath = MemoryFixtureSupport::fixtureDirectory($runPrefix, $fixtureId).'/manifest.json';

        if (!is_file($manifestPath)) {
            $this->components->error("Manifest missing: {$manifestPath}");

            return self::FAILURE;
        }

        $manifest = MemoryFixtureSupport::readJsonFile($manifestPath);
        $paths = is_array($manifest['paths'] ?? null) ? $manifest['paths'] : [];
        $model = UploadModels::session();
        $cleaned = 0;

        foreach (['tus_final_patch', 's3_putpart', 's3_complete_postfix'] as $pathId) {
            $uploadId = $paths[$pathId]['upload_id'] ?? null;

            if (!is_string($uploadId) || $uploadId === '') {
                continue;
            }

            $session = $model::query()->where('upload_id', $uploadId)->first();

            if (!$session instanceof UploadSession) {
                continue;
            }

            $sessions->abort($session);

            // A successful HTTP complete may already have committed the session. The
            // lifecycle abort is then intentionally a no-op, but proxied multipart part
            // objects are still load-harness state and must not accumulate across 45 runs.
            $sessions->handlerFor($session)->abort($session);
            $stagedUpload->discard($session);
            $cleaned++;
        }

        $this->components->info("Cleaned API state for {$cleaned} upload session(s).");

        return self::SUCCESS;
    }

    private function probeObject(): int
    {
        $fixtureId = (string) $this->option('fixture-id');
        $runPrefix = (string) $this->option('run-prefix');

        if ($fixtureId === '' || $runPrefix === '') {
            $this->components->error('--fixture-id and --run-prefix are required for probe-object.');

            return self::FAILURE;
        }

        $manifestPath = MemoryFixtureSupport::fixtureDirectory($runPrefix, $fixtureId).'/manifest.json';

        if (!is_file($manifestPath)) {
            $this->components->error("Manifest missing: {$manifestPath}");

            return self::FAILURE;
        }

        $manifest = MemoryFixtureSupport::readJsonFile($manifestPath);
        $payloadPath = (string) ($manifest['payload_path'] ?? '');

        if ($payloadPath === '' || !is_file($payloadPath)) {
            $this->components->error('Fixture payload_path missing on disk.');

            return self::FAILURE;
        }

        $sha256 = MemoryFixtureSupport::hashFileBounded($payloadPath);
        $this->line(json_encode([
            'fixture_id' => $fixtureId,
            'size_bytes' => (int) ($manifest['size_bytes'] ?? 0),
            'probe_object_sha256' => $sha256,
            'manifest_sha256' => (string) ($manifest['sha256'] ?? ''),
            'match' => $sha256 === ($manifest['sha256'] ?? ''),
        ], JSON_UNESCAPED_SLASHES));

        return $sha256 === ($manifest['sha256'] ?? '') ? self::SUCCESS : self::FAILURE;
    }

    private function show(): int
    {
        $fixtureId = (string) $this->option('fixture-id');
        $runPrefix = (string) $this->option('run-prefix');

        if ($fixtureId === '' || $runPrefix === '') {
            $this->components->error('--fixture-id and --run-prefix are required for show.');

            return self::FAILURE;
        }

        $manifestPath = MemoryFixtureSupport::fixtureDirectory($runPrefix, $fixtureId).'/manifest.json';

        if (!is_file($manifestPath)) {
            $this->components->error("Manifest missing: {$manifestPath}");

            return self::FAILURE;
        }

        $this->line(json_encode(MemoryFixtureSupport::readJsonFile($manifestPath), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->components->error("Unknown action [{$action}] — use prepare|cleanup|probe-object|show.");

        return self::FAILURE;
    }

    private static function grantOwnerOnce(Node $root, int $actorId): void
    {
        $grantModel = config('vaulter-drive.models.grant');
        $subjectType = (new TestUser)->getMorphClass();

        $exists = $grantModel::query()
            ->where('node_id', $root->getKey())
            ->where('subject_type', $subjectType)
            ->where('subject_id', $actorId)
            ->exists();

        if (!$exists) {
            AccessTestHelpers::grant($root, GrantLevel::Owner, subjectId: $actorId);
        }
    }
}
