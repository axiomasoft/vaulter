<?php

declare(strict_types=1);

namespace Vaulter\Load\Console;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Deterministic P7.2 upload/CAS/outbox fixtures. Cleanup is run-prefix scoped.
 * Current multipart sessions store Flysystem part keys, not AWS UploadId.
 */
final class ChaosFixtureSupport
{
    public static function fixturesRoot(): string
    {
        return storage_path('app/p7-chaos-fixtures');
    }

    public static function fixtureDirectory(string $runPrefix, string $fixtureId): string
    {
        return self::fixturesRoot().'/'.self::sanitizeToken($runPrefix).'/'.self::sanitizeToken($fixtureId);
    }

    public static function sanitizeToken(string $value): string
    {
        $token = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value) ?? '';
        $token = trim($token, '-');

        return $token !== '' ? $token : 'default';
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildManifest(string $fixtureId, string $runPrefix): array
    {
        $uploadId = self::token($runPrefix, $fixtureId, 'upload');
        $multipartId = self::token($runPrefix, $fixtureId, 'multipart');
        $payload = $runPrefix."\0".$fixtureId."\0cas";
        $sha256 = hash('sha256', $payload);

        return [
            'fixture_id' => $fixtureId,
            'run_prefix' => $runPrefix,
            'performance_verdict' => false,
            'multipart_cleanup' => 'flysystem-part-keys',
            'aws_upload_id' => null,
            'uploads' => [
                'tus' => [
                    'upload_id' => $uploadId,
                    'staged_key' => 'tmp/uploads/'.$uploadId,
                ],
                's3_multipart' => [
                    'upload_id' => $multipartId,
                    'part_keys' => [
                        'tmp/uploads/'.$multipartId.'.parts/1',
                        'tmp/uploads/'.$multipartId.'.parts/2',
                    ],
                    'aws_upload_id' => null,
                ],
            ],
            'cas' => [
                'sha256' => $sha256,
                'size_bytes' => strlen($payload),
                'staged_temp_prefix' => 'vaulter_cas_',
                'tmpdir' => '/var/tmp/vaulter-chaos',
            ],
            'outbox' => [
                'event_id' => self::token($runPrefix, $fixtureId, 'outbox'),
            ],
            'barriers' => [
                'owner' => 'load-harness',
                'sleep_is_not_barrier' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public static function verifyManifest(array $manifest): void
    {
        foreach (['fixture_id', 'run_prefix', 'uploads', 'cas', 'outbox'] as $key) {
            if (!isset($manifest[$key])) {
                throw new RuntimeException("Fixture field [{$key}] is missing.");
            }
        }

        if (!array_key_exists('aws_upload_id', $manifest) || $manifest['aws_upload_id'] !== null) {
            throw new RuntimeException('Current handler must not claim an AWS UploadId.');
        }

        if (($manifest['multipart_cleanup'] ?? null) !== 'flysystem-part-keys') {
            throw new RuntimeException('Multipart cleanup must target Flysystem part keys.');
        }

        $parts = $manifest['uploads']['s3_multipart']['part_keys'] ?? null;
        if (!is_array($parts) || $parts === []) {
            throw new RuntimeException('Multipart part keys are missing.');
        }
    }

    /**
     * @param array<int, string> $paths
     */
    public static function cleanupPaths(array $paths, string $directory): void
    {
        $realDirectory = realpath($directory);
        $root = realpath(self::fixturesRoot());
        if ($realDirectory === false || $root === false || !str_starts_with($realDirectory, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Refusing to delete a path outside the fixture root.');
        }

        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $real = realpath($path);
            if ($real === false) {
                continue;
            }
            if (!str_starts_with($real, $realDirectory)) {
                throw new RuntimeException('Refusing to delete a path outside the fixture directory.');
            }
            if (is_file($real)) {
                File::delete($real);
            }
        }

        File::deleteDirectory($realDirectory);
    }

    private static function token(string $runPrefix, string $fixtureId, string $kind): string
    {
        return substr(hash('sha256', $runPrefix.'|'.$fixtureId.'|'.$kind), 0, 32);
    }
}
