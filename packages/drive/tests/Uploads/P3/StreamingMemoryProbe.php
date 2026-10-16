<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use GuzzleHttp\Psr7\Stream;
use Illuminate\Support\Facades\Storage;
use PHPUnit\TextUI\Configuration\Builder;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\TestCase;
use Vaulter\Facades\Vaulter;
use Vaulter\Schema\SchemaBuilder;

/** Deterministic nonseekable source; never materializes the whole payload. */
final class P37ProbeStream
{
    public mixed $context;

    private int $remaining = 209715200;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        if ($count > 65536) {
            throw new RuntimeException('Unbounded source read.');
        }
        $length = min($count, $this->remaining);
        $this->remaining -= $length;

        return str_repeat('a', $length);
    }

    public function stream_eof(): bool
    {
        return $this->remaining === 0;
    }

    public function stream_stat(): array
    {
        return [];
    }
}

final class P37ProbeCase extends TestCase
{
    public function bootProbe(): void
    {
        $this->setUp();
    }

    public function endProbe(): void
    {
        $this->tearDown();
    }
}

if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
    throw new RuntimeException('Memory probe requires an isolated SQLite test environment.');
}
(new Builder)->build(['StreamingMemoryProbe', '--no-configuration']);
$case = new P37ProbeCase('streamingProbe');
$case->bootProbe();
$streams = [];
try {
    Storage::fake('local');
    $drive = Vaulter::drive(DriveTestHelpers::host())->asSystem('streaming memory probe');
    stream_wrapper_register('vaulterprobe', P37ProbeStream::class);
    $hash = hash_init('sha256');
    for ($i = 0; $i < 3200; $i++) {
        hash_update($hash, str_repeat('a', 65536));
    }
    $digest = hash_final($hash);
    foreach (['resource', 'psr'] as $kind) {
        $resource = fopen('vaulterprobe://bytes', 'rb');
        if (!is_resource($resource)) {
            throw new RuntimeException('Probe source unavailable.');
        }
        $streams[] = $resource;
        $psr = $kind === 'psr' ? new Stream($resource) : null;
        $source = $psr === null ? UploadSource::fromStream($resource, 'resource.txt', 1) : UploadSource::fromPsrStream($psr, 'psr.txt');
        $node = $drive->files()->upload($source);
        $revision = DriveTestHelpers::model($node)->file()->firstOrFail()->currentRevision()->firstOrFail();
        $blob = $revision->blob()->firstOrFail();
        if ($revision->size_bytes !== 209715200 || $revision->sha256 !== $digest || $blob->sha256 !== $digest
            || hash_file('sha256', Storage::disk('local')->path($blob->storage_key)) !== $digest
            || !is_resource($resource) || ($psr !== null && !$psr->isReadable()) || memory_get_peak_usage(true) >= 67108864) {
            throw new RuntimeException('Streaming size/hash/memory/ownership invariant failed.');
        }
        echo json_encode(['source' => $kind, 'bytes' => $revision->size_bytes, 'sha256' => $digest, 'peak_bytes' => memory_get_peak_usage(true), 'borrowed_open' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    }
    // The fake disk and database belong exclusively to this isolated probe.
    foreach (Storage::disk('local')->allFiles() as $path) {
        Storage::disk('local')->delete($path);
    }
    app(SchemaBuilder::class)->dropAll();
} finally {
    foreach ($streams as $stream) {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
    $case->endProbe();
}
