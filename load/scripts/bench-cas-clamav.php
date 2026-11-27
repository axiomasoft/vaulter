#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Streaming 1 GiB CAS upload (Sha256ContentStore::stage -> promote -> get verification)
 * + ClamAV `zINSTREAM\0` memory benchmark.
 *
 * Enforces engineering-standards.md §3.8:
 *   - <= 64 MiB PHP worker RAM while staging 1 GiB, scanning via clamd `zINSTREAM\0`,
 *     promoting to CAS storage through FlysystemStorageDriver, and verifying streamed readback.
 *   - Full profile requires a real clamd socket (--clamd=tcp://... or unix:///...) verified
 *     via `zVERSION\0` and refuses ephemeral `--clamd=auto` loopback responders.
 *
 * Modes:
 *   php load/scripts/bench-cas-clamav.php --mode=dry-run
 *   php load/scripts/bench-cas-clamav.php --mode=smoke --clamd=auto --output=load/results/s4-slo/cas-clamav-smoke.json
 *   php load/scripts/bench-cas-clamav.php --mode=full --size-mib=1024 --clamd=tcp://127.0.0.1:3310 --output=load/results/s4-slo/cas-clamav-1gib.json
 */

use Illuminate\Foundation\Application;
use Orchestra\Testbench\Concerns\CreatesApplication;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Cas\Sha256ContentStore;
use Vaulter\Media\Configuration\MediaOptions;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\ValueObjects\Cas\BlobScope;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const CEILING_BYTES = 67_108_864; // 64 MiB
const CLAMD_CHUNK_BYTES = 262_144; // 256 KiB zINSTREAM frame size

/** @return array<string, string|true> */
function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $body = substr($arg, 2);
        if (str_contains($body, '=')) {
            [$k, $v] = explode('=', $body, 2);
            $out[$k] = $v;
        } else {
            $out[$body] = true;
        }
    }

    return $out;
}

function readVmHwmBytes(): int
{
    $status = @file_get_contents('/proc/self/status');
    if (is_string($status) && preg_match('/^VmHWM:\s+(\d+)\s+kB/m', $status, $m) === 1) {
        return (int) $m[1] * 1024;
    }

    return memory_get_peak_usage(true);
}

function removeDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iter as $entry) {
        $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($dir);
}

function resolveMode(array $args): string
{
    if (isset($args['self-test']) || ($args['mode'] ?? null) === 'self-test') {
        return 'self-test';
    }
    if (isset($args['dry-run']) || ($args['mode'] ?? null) === 'dry-run') {
        return 'dry-run';
    }
    if (isset($args['smoke']) || ($args['mode'] ?? null) === 'smoke') {
        return 'smoke';
    }
    if (($args['mode'] ?? null) === 'full' || isset($args['size-mib'])) {
        return 'full';
    }

    return 'dry-run';
}

/**
 * Parse a strictly NUL-terminated ClamD `zVERSION\0` reply (no CR/LF before NUL)
 * and require ClamAV >= 1.5.5.
 */
function parseClamdVersionReply(string|false $raw): string
{
    if (!is_string($raw) || $raw === '' || !str_ends_with($raw, "\0") || substr_count($raw, "\0") !== 1) {
        throw new RuntimeException('clamd zVERSION reply is not cleanly NUL-terminated (got: '.var_export($raw, true).')');
    }
    $clean = substr($raw, 0, -1);
    if (strpbrk($clean, "\r\n") !== false
        || preg_match('~\AClamAV ([0-9]+\.[0-9]+\.[0-9]+)(?:/[^\r\n]+)?\z~', $clean, $match) !== 1
        || version_compare($match[1], '1.5.5', '<')) {
        throw new RuntimeException('clamd target did not return a ClamAV >= 1.5.5 zVERSION banner (got: '.var_export($clean, true).')');
    }

    return $clean;
}

/**
 * Strictly parse a ClamD `zINSTREAM\0` reply per ClamdProtocol (exact `stream: OK\0` only;
 * rejects CR/LF before NUL, any FOUND/ERROR, trailing payload, or unframed substring match).
 *
 * @return array{clean: bool, verdict: string, reason: string}
 */
function parseClamdInstreamReply(string|false $raw): array
{
    if (!is_string($raw) || $raw === '') {
        return ['clean' => false, 'verdict' => '', 'reason' => 'empty_reply'];
    }
    if (!str_ends_with($raw, "\0") || substr_count($raw, "\0") !== 1) {
        return [
            'clean' => false,
            'verdict' => str_replace(["\0", "\r", "\n"], ['\\0', '\\r', '\\n'], $raw),
            'reason' => 'invalid_framing',
        ];
    }
    $body = substr($raw, 0, -1);
    if (strpbrk($body, "\r\n") !== false) {
        return [
            'clean' => false,
            'verdict' => str_replace(["\r", "\n"], ['\\r', '\\n'], $body),
            'reason' => 'invalid_framing',
        ];
    }
    if (preg_match('/\bFOUND\b/i', $body) === 1) {
        return ['clean' => false, 'verdict' => $body, 'reason' => 'infected'];
    }
    if (str_contains($body, 'size limit exceeded')) {
        return ['clean' => false, 'verdict' => $body, 'reason' => 'too_large'];
    }
    if (preg_match('/\bERROR\b/i', $body) === 1) {
        return ['clean' => false, 'verdict' => $body, 'reason' => 'scanner_error'];
    }
    if ($body === 'stream: OK') {
        return ['clean' => true, 'verdict' => $body, 'reason' => 'clean'];
    }

    return ['clean' => false, 'verdict' => $body, 'reason' => 'unexpected_reply'];
}

/**
 * Drain the full ClamD socket reply until EOF (never stopping early at the first NUL).
 *
 * @param resource $sock
 */
function readClamdReply($sock, int $timeoutSeconds = 60): string
{
    stream_set_timeout($sock, $timeoutSeconds);
    $reply = '';
    $maxBytes = 4_096;
    while (!feof($sock)) {
        $chunk = @fread($sock, ($maxBytes + 1) - strlen($reply));
        $meta = stream_get_meta_data($sock);
        if (($meta['timed_out'] ?? false) === true) {
            throw new RuntimeException('clamd socket read timed out');
        }
        if ($chunk === false) {
            throw new RuntimeException('clamd socket read failed');
        }
        if ($chunk === '') {
            if (feof($sock)) {
                break;
            }
            usleep(1_000);

            continue;
        }
        $reply .= $chunk;
        if (strlen($reply) > $maxBytes) {
            throw new RuntimeException('clamd reply exceeded 4096-byte limit');
        }
    }

    return $reply;
}

function probeRealClamd(string $target): string
{
    $sock = @stream_socket_client($target, $errno, $errstr, 5);
    if ($sock === false) {
        throw new RuntimeException("Unable to connect to real clamd at {$target}: {$errstr} ({$errno})");
    }
    try {
        fwrite($sock, "zVERSION\0");
        $banner = readClamdReply($sock, 5);
    } finally {
        fclose($sock);
    }

    return parseClamdVersionReply($banner);
}

function emitReport(?string $outputFile, array $report): void
{
    $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    if ($outputFile !== null && $outputFile !== '') {
        @mkdir(dirname($outputFile), 0777, true);
        file_put_contents($outputFile, $encoded);
    }
    echo $encoded;
}

$args = parseArgs($argv);
$mode = resolveMode($args);
if ($mode === 'self-test') {
    $cases = [
        ["stream: OK\0", true, 'clean'],
        ["stream: OK\r\n\0", false, 'invalid_framing'],
        ["stream: OK\n\0", false, 'invalid_framing'],
        ["stream: OK\r\0", false, 'invalid_framing'],
        ["stream: Virus-OK FOUND\0", false, 'infected'],
        ["stream: Eicar-Test-Signature FOUND\0", false, 'infected'],
        ["FOUND OK\0", false, 'infected'],
        ["stream: OK FOUND\0", false, 'infected'],
        ['stream: OK', false, 'invalid_framing'],
        ["stream: OK\0extra\0", false, 'invalid_framing'],
        ["stream: OK\0stream: Eicar-Test-Signature FOUND\0", false, 'invalid_framing'],
        ["stream: OK trailing\0", false, 'unexpected_reply'],
        ["stream: size limit exceeded. ERROR\0", false, 'too_large'],
        ["stream: INSTREAM size limit exceeded. ERROR\0", false, 'too_large'],
        ["stream: error parsing command. ERROR\0", false, 'scanner_error'],
        ['', false, 'empty_reply'],
        [false, false, 'empty_reply'],
    ];
    foreach ($cases as [$raw, $expectedClean, $expectedReason]) {
        $parsed = parseClamdInstreamReply($raw);
        if ($parsed['clean'] !== $expectedClean || $parsed['reason'] !== $expectedReason) {
            fwrite(STDERR, 'Self-test failed for '.var_export($raw, true).': '.json_encode($parsed)."\n");
            exit(1);
        }
    }
    if (parseClamdVersionReply("ClamAV 1.5.5/27432/Mon Nov 24 00:00:00 2026\0") === '') {
        fwrite(STDERR, "Self-test failed for valid ClamAV 1.5.5 version\n");
        exit(1);
    }
    foreach (["ClamAV 1.5.4/1/x\0", "ClamAV 1.5.5/1/x\r\n\0", 'ClamAV 1.5.5', "LoopbackSmokeScanner 0.0\0"] as $badBanner) {
        try {
            parseClamdVersionReply($badBanner);
            fwrite(STDERR, 'Self-test accepted invalid version banner: '.var_export($badBanner, true)."\n");
            exit(1);
        } catch (RuntimeException) {
        }
    }
    // Verify readClamdReply drains until EOF across multiple writes instead of stopping at first NUL.
    $socketCases = [
        [['stream: OK'."\0"], true, 'clean'],
        [['stream: OK'."\0", 'stream: Eicar-Test-Signature FOUND'."\0"], false, 'invalid_framing'],
        [['stream: OK'."\r\n\0"], false, 'invalid_framing'],
    ];
    foreach ($socketCases as [$chunks, $expectedClean, $expectedReason]) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            fwrite(STDERR, "Self-test failed to open socket pair\n");
            exit(1);
        }
        [$reader, $writer] = $pair;
        $child = pcntl_fork();
        if ($child === 0) {
            fclose($reader);
            foreach ($chunks as $idx => $chunk) {
                fwrite($writer, $chunk);
                fflush($writer);
                if ($idx + 1 < count($chunks)) {
                    usleep(15_000);
                }
            }
            fclose($writer);
            exit(0);
        }
        fclose($writer);
        $rawReply = readClamdReply($reader, 5);
        fclose($reader);
        pcntl_waitpid($child, $st);
        $parsed = parseClamdInstreamReply($rawReply);
        if ($parsed['clean'] !== $expectedClean || $parsed['reason'] !== $expectedReason) {
            fwrite(STDERR, 'Self-test socket framing failed for '.json_encode($chunks).': '.json_encode($parsed).' raw='.var_export($rawReply, true)."\n");
            exit(1);
        }
    }
    echo json_encode(['status' => 'SELF_TEST_PASS', 'cases' => count($cases) + 5 + count($socketCases)], JSON_PRETTY_PRINT)."\n";
    exit(0);
}

$isSmoke = $mode === 'smoke';
$isFull = $mode === 'full';
$sizeMiB = (int) ($args['size-mib'] ?? ($isSmoke ? 64 : 1024));
if ($sizeMiB <= 0) {
    fwrite(STDERR, "size-mib must be positive\n");
    exit(2);
}

$clamdDefault = $isFull ? (getenv('CLAMD_TARGET') ?: '') : 'auto';
$clamdTarget = is_string($args['clamd'] ?? null) ? trim($args['clamd']) : $clamdDefault;
$casDisk = is_string($args['disk'] ?? null) ? trim($args['disk']) : 'local';
$outputFile = is_string($args['output'] ?? null) ? $args['output'] : null;

$tmpFree = @disk_free_space(sys_get_temp_dir()) ?: 0;

if ($mode === 'dry-run') {
    $report = [
        'status' => 'DRY_RUN',
        'benchmark' => 'cas_stage_promote_and_clamd_zinstream',
        'mode' => 'dry-run',
        'size_mib' => $sizeMiB,
        'ceiling_bytes' => CEILING_BYTES,
        'ceiling_mib' => 64,
        'clamd_target' => $clamdTarget,
        'clamd_chunk_bytes' => CLAMD_CHUNK_BYTES,
        'cas_driver' => FlysystemStorageDriver::class,
        'content_store' => Sha256ContentStore::class,
        'cas_disk' => $casDisk,
        'full_requires_real_clamd' => true,
        'temp_free_gib' => round($tmpFree / 1_073_741_824.0, 2),
    ];
    emitReport($outputFile, $report);
    exit(0);
}

if ($isFull && ($clamdTarget === '' || $clamdTarget === 'auto')) {
    fwrite(STDERR, "Full mode requires a real clamd socket (--clamd=tcp://127.0.0.1:3310 or unix:///...); --clamd=auto loopback is forbidden.\n");
    exit(1);
}

if ($clamdTarget === '') {
    fwrite(STDERR, "--clamd target cannot be empty\n");
    exit(1);
}

$expectedBytes = $sizeMiB * 1_048_576;
if ($tmpFree > 0 && $tmpFree < (int) ($expectedBytes * 2.2)) {
    fwrite(STDERR, "Insufficient temp disk space for stage + Flysystem local CAS promote ({$sizeMiB} MiB).\n");
    exit(1);
}

$clamdVersion = '';
$clamdPid = null;
if ($clamdTarget === 'auto') {
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        throw new RuntimeException("Unable to bind loopback smoke clamd socket: {$errstr}");
    }
    $localName = stream_socket_get_name($server, false);
    $clamdPid = pcntl_fork();
    if ($clamdPid === 0) {
        // Ephemeral loopback zINSTREAM responder for --mode=smoke only (refused in full mode).
        for ($connIdx = 0; $connIdx < 2; $connIdx++) {
            $conn = @stream_socket_accept($server, 30);
            if ($conn === false) {
                break;
            }
            $cmd = '';
            while (!feof($conn)) {
                $ch = fread($conn, 1);
                if ($ch === false || $ch === '' || $ch === "\0") {
                    break;
                }
                $cmd .= $ch;
                if (strlen($cmd) > 32) {
                    break;
                }
            }
            if ($cmd === 'zVERSION') {
                fwrite($conn, "LoopbackSmokeScanner 0.0\0");
                fclose($conn);

                continue;
            }
            if ($cmd === 'zINSTREAM') {
                while (!feof($conn)) {
                    $lenRaw = '';
                    while (strlen($lenRaw) < 4 && !feof($conn)) {
                        $part = fread($conn, 4 - strlen($lenRaw));
                        if ($part === false || $part === '') {
                            break;
                        }
                        $lenRaw .= $part;
                    }
                    if (strlen($lenRaw) < 4) {
                        break;
                    }
                    $chunkLen = unpack('N', $lenRaw)[1];
                    if ($chunkLen === 0) {
                        break;
                    }
                    $remaining = $chunkLen;
                    while ($remaining > 0 && !feof($conn)) {
                        $buf = fread($conn, min(CLAMD_CHUNK_BYTES, $remaining));
                        if ($buf === false || $buf === '') {
                            break;
                        }
                        $remaining -= strlen($buf);
                    }
                }
                fwrite($conn, "stream: OK\0");
            }
            fclose($conn);
        }
        fclose($server);
        exit(0);
    }
    fclose($server);
    $clamdTarget = 'tcp://'.$localName;
    $clamdVersion = 'LoopbackSmokeScanner 0.0';
} else {
    $clamdVersion = probeRealClamd($clamdTarget);
}

$workRoot = sys_get_temp_dir().'/vaulter-cas-bench-'.getmypid();
$casRoot = $workRoot.'/cas-disk';
@mkdir($casRoot, 0775, true);

/** @var Application $app */
$app = (new class($casRoot, $casDisk)
{
    use CreatesApplication;

    public function __construct(private readonly string $casRoot, private readonly string $casDisk) {}

    protected function getPackageProviders($app): array
    {
        return [
            VaulterServiceProvider::class,
            VaulterMediaServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('vaulter.ids.strategy', 'bigint');
        $app['config']->set('vaulter-media.disk', $this->casDisk);
        if ($this->casDisk === 'local') {
            $app['config']->set('filesystems.disks.local', ['driver' => 'local', 'root' => $this->casRoot]);
        }
    }
})->createApplication();

if ($casDisk === 'local') {
    $app['config']->set('filesystems.disks.local', ['driver' => 'local', 'root' => $casRoot]);
}
$options = MediaOptions::fromArray(['disk' => $casDisk]);
$driver = new FlysystemStorageDriver($options);
$store = new Sha256ContentStore($driver);
if (!$store instanceof ContentStore) {
    throw new RuntimeException('Unexpected ContentStore implementation');
}

// Spawn child producer writing $sizeMiB MiB into a UNIX socketpair for Sha256ContentStore::stage().
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($pair === false) {
    throw new RuntimeException('Unable to open socket pair');
}
[$readPipe, $writePipe] = $pair;
$producerPid = pcntl_fork();
if ($producerPid === 0) {
    fclose($readPipe);
    $block = str_repeat('0123456789abcdef', 65536); // exactly 1 MiB
    for ($m = 0; $m < $sizeMiB; $m++) {
        $written = 0;
        while ($written < 1_048_576) {
            $n = fwrite($writePipe, substr($block, $written));
            if ($n === false || $n === 0) {
                exit(1);
            }
            $written += $n;
        }
    }
    fclose($writePipe);
    exit(0);
}
fclose($writePipe);

$staged = null;
$casKey = null;
$report = null;
$pass = false;

try {
    $tStart = hrtime(true);
    $staged = $store->stage($readPipe);
    fclose($readPipe);
    $stageSec = (hrtime(true) - $tStart) / 1_000_000_000.0;
    pcntl_waitpid($producerPid, $prodStatus);
    $prodExit = pcntl_wifexited($prodStatus) ? pcntl_wexitstatus($prodStatus) : -1;

    // Phase 2: Stream staged file over zINSTREAM\0 to clamd.
    $clamSock = stream_socket_client($clamdTarget, $errno, $errstr, 15);
    if ($clamSock === false) {
        throw new RuntimeException("Unable to connect to clamd at {$clamdTarget}: {$errstr}");
    }
    $scanStart = hrtime(true);
    fwrite($clamSock, "zINSTREAM\0");
    $stagedStream = $staged->readStream();
    try {
        while (!feof($stagedStream)) {
            $chunk = fread($stagedStream, CLAMD_CHUNK_BYTES);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $frame = pack('N', strlen($chunk)).$chunk;
            $offset = 0;
            $frameLen = strlen($frame);
            while ($offset < $frameLen) {
                $sent = fwrite($clamSock, substr($frame, $offset));
                if ($sent === false || $sent === 0) {
                    throw new RuntimeException('zINSTREAM write failed');
                }
                $offset += $sent;
            }
        }
    } finally {
        fclose($stagedStream);
    }
    fwrite($clamSock, pack('N', 0));
    $verdict = readClamdReply($clamSock, 60);
    fclose($clamSock);
    $scanSec = (hrtime(true) - $scanStart) / 1_000_000_000.0;
    $clamdParsed = parseClamdInstreamReply($verdict);
    $clamdOk = $clamdParsed['clean'];

    // Phase 3: Promote staged payload to real Flysystem CAS and verify streamed readback.
    $casKey = $store->keyFor(BlobScope::tenant('101'), $staged->sha256());
    $promoteStart = hrtime(true);
    $store->promote($staged, $casKey);
    $promoteSec = (hrtime(true) - $promoteStart) / 1_000_000_000.0;

    $casExists = $store->exists($casKey);
    $readHandle = $store->get($casKey);
    $hashCtx = hash_init('sha256');
    $readBytes = 0;
    if (is_resource($readHandle)) {
        try {
            while (!feof($readHandle)) {
                $buf = fread($readHandle, CLAMD_CHUNK_BYTES);
                if ($buf === false || $buf === '') {
                    break;
                }
                $readBytes += strlen($buf);
                hash_update($hashCtx, $buf);
            }
        } finally {
            fclose($readHandle);
        }
    } elseif (is_string($readHandle)) {
        $readBytes = strlen($readHandle);
        hash_update($hashCtx, $readHandle);
    }
    $readSha = hash_final($hashCtx);
    $store->delete($casKey);
    $casKey = null;

    $phpPeak = memory_get_peak_usage(true);
    $vmHwm = readVmHwmBytes();
    $budgetOk = $phpPeak <= CEILING_BYTES && $vmHwm <= CEILING_BYTES;
    $casVerified = $casExists && $readSha === $staged->sha256() && $readBytes === $expectedBytes;
    $fullClamdValid = preg_match('~\AClamAV ([0-9]+\.[0-9]+\.[0-9]+)(?:/[^\r\n]+)?\z~', $clamdVersion, $vm) === 1
        && version_compare($vm[1], '1.5.5', '>=');
    $clamdVerified = $clamdOk && ($isSmoke || $fullClamdValid);
    $pass = $prodExit === 0
        && $staged->sizeBytes() === $expectedBytes
        && $clamdVerified
        && $casVerified
        && $budgetOk;

    $report = [
        'status' => $pass ? 'MEASURED_PASS' : 'MEASURED_FAIL',
        'mode' => $mode,
        'payload_bytes' => $staged->sizeBytes(),
        'payload_mib' => round($staged->sizeBytes() / 1_048_576.0, 2),
        'sha256' => $staged->sha256(),
        'producer_exit_code' => $prodExit,
        'clamd_target' => $clamdTarget,
        'clamd_version' => $clamdVersion,
        'clamd_loopback' => $clamdTarget !== ($args['clamd'] ?? '') && $isSmoke,
        'zinstream_verdict' => $clamdParsed['verdict'],
        'zinstream_reason' => $clamdParsed['reason'],
        'cas_driver' => FlysystemStorageDriver::class,
        'cas_disk' => $casDisk,
        'cas_promoted_exists' => $casExists,
        'cas_readback_bytes' => $readBytes,
        'cas_readback_sha256_match' => $readSha === $staged->sha256(),
        'stage_duration_seconds' => round($stageSec, 3),
        'scan_duration_seconds' => round($scanSec, 3),
        'promote_duration_seconds' => round($promoteSec, 3),
        'php_peak_bytes' => $phpPeak,
        'php_peak_mib' => round($phpPeak / 1_048_576.0, 2),
        'vm_hwm_bytes' => $vmHwm,
        'vm_hwm_mib' => round($vmHwm / 1_048_576.0, 2),
        'ceiling_mib' => 64,
        'within_64mib_budget' => $budgetOk,
    ];
} finally {
    if ($casKey !== null) {
        try {
            $store->delete($casKey);
        } catch (Throwable) {
        }
    }
    if ($staged !== null) {
        $staged->discard();
    }
    if ($clamdPid !== null) {
        pcntl_waitpid($clamdPid, $clamStatus);
    }
    removeDirectory($workRoot);
}

emitReport($outputFile, $report);
exit($pass ? 0 : 1);
