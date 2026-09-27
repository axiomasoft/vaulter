<?php

declare(strict_types=1);

namespace Vaulter\Load\Console;

use RuntimeException;

/**
 * Bounded streaming helpers for P4.2 path fixtures (no whole-file reads in PHP).
 */
final class MemoryFixtureSupport
{
    public const string SCHEMA_VERSION = 'p4-memory-fixtures/v1';

    public const int WRITE_CHUNK_BYTES = 1_048_576;

    public const int HASH_CHUNK_BYTES = 1_048_576;

    /**
     * @return array{size_bytes: int, sha256: string, payload_path: string}
     */
    public static function writePayloadFile(string $path, int $sizeBytes, string $fixtureId): array
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create fixture directory [{$directory}].");
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open fixture payload [{$path}].");
        }

        $hash = hash_init('sha256');
        $written = 0;
        $seed = self::deterministicSeed($fixtureId);

        while ($written < $sizeBytes) {
            $chunkLen = min(self::WRITE_CHUNK_BYTES, $sizeBytes - $written);
            $chunk = self::deterministicChunk($seed, $written, $chunkLen);
            hash_update($hash, $chunk);
            $bytes = fwrite($handle, $chunk);

            if ($bytes === false) {
                fclose($handle);
                throw new RuntimeException('Fixture write failed.');
            }

            $written += $bytes;
        }

        fclose($handle);

        return [
            'size_bytes' => $sizeBytes,
            'sha256' => hash_final($hash),
            'payload_path' => $path,
        ];
    }

    public static function hashFileBounded(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open fixture for hashing [{$path}].");
        }

        $hash = hash_init('sha256');

        while (!feof($handle)) {
            $buffer = fread($handle, self::HASH_CHUNK_BYTES);

            if ($buffer === false || $buffer === '') {
                break;
            }

            hash_update($hash, $buffer);
        }

        fclose($handle);

        return hash_final($hash);
    }

    public static function parseSize(string $raw): int
    {
        $normalized = strtolower(trim($raw));

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(b|kib|mib|gib|kb|mb|gb)$/', $normalized, $matches) !== 1) {
            throw new RuntimeException("Invalid fixture size [{$raw}] — use e.g. 5MiB, 500MiB, 5GiB.");
        }

        $value = (float) $matches[1];
        $unit = $matches[2];

        $multiplier = match ($unit) {
            'b' => 1,
            'kib', 'kb' => 1024,
            'mib', 'mb' => 1024 ** 2,
            'gib', 'gb' => 1024 ** 3,
            default => throw new RuntimeException("Unsupported size unit [{$unit}]."),
        };

        $bytes = (int) round($value * $multiplier);

        if ($bytes <= 0) {
            throw new RuntimeException("Fixture size must be positive [{$raw}].");
        }

        return $bytes;
    }

    public static function fixturesRoot(): string
    {
        return storage_path('app/p4-memory-fixtures');
    }

    public static function fixtureDirectory(string $runPrefix, string $fixtureId): string
    {
        return self::fixturesRoot().'/'.self::sanitizeToken($runPrefix).'/'.self::sanitizeToken($fixtureId);
    }

    public static function sanitizeToken(string $value): string
    {
        $token = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value) ?? '';

        return trim($token, '-') !== '' ? trim($token, '-') : 'default';
    }

    /**
     * @return array<string, mixed>
     */
    public static function readJsonFile(string $path, int $maxBytes = 1_048_576): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open JSON file [{$path}].");
        }

        $buffer = '';

        while (!feof($handle) && strlen($buffer) < $maxBytes) {
            $chunk = fread($handle, min(self::HASH_CHUNK_BYTES, $maxBytes - strlen($buffer)));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
        }

        fclose($handle);

        $decoded = json_decode($buffer, true);

        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid JSON in [{$path}].");
        }

        return $decoded;
    }

    private static function deterministicSeed(string $fixtureId): string
    {
        return hash('sha256', 'p4-memory-fixture:'.$fixtureId);
    }

    private static function deterministicChunk(string $seed, int $offset, int $length): string
    {
        $out = '';
        $cursor = $offset;

        while (strlen($out) < $length) {
            $block = hash('sha256', $seed.'@'.$cursor, true);
            $need = $length - strlen($out);
            $out .= substr($block, 0, $need);
            $cursor += strlen($block);
        }

        return $out;
    }
}
