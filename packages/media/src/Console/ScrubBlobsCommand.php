<?php

declare(strict_types=1);

namespace Vaulter\Media\Console;

use Illuminate\Console\Command;
use Vaulter\Attributes\Internal;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Media\Cas\BlobScrubber;
use Vaulter\Tenancy\Tenancy;

/**
 * The integrity scrub of the CAS (D49): reads a sample of committed blobs back from the storage and marks the ones
 * whose object is missing, has another size or another sha256. It never deletes bytes, pins or rows. ACL-blind,
 * system context. The sample is the never-checked blobs first, then the least recently checked; the run ends at the
 * first of --limit, --max-bytes and --max-seconds (defaults: vaulter-media.scrub.*). --dry-run reads and reports
 * and writes nothing. It is not scheduled by default: a run reads the objects, which costs on an object store.
 *
 * EXIT: 0 — every blob of the sample is intact; 1 — failures found (or objects that could not be read); 2 — an
 * option is not valid.
 */
#[Internal]
final class ScrubBlobsCommand extends Command
{
    use OpensWriteScope;

    protected $signature = 'vaulter:blobs:scrub
        {--limit= : Most blobs to visit (default vaulter-media.scrub.limit)}
        {--max-bytes= : Stop after this many bytes were read (default vaulter-media.scrub.max_bytes)}
        {--max-seconds= : Stop after this many seconds (default vaulter-media.scrub.max_seconds)}
        {--scope= : Only the blobs of one scope key (tenant:<id>, drive:<id> or off:<id>)}
        {--dry-run : Read and report, write nothing}';

    protected $description = 'Verify the size and sha256 of a sample of stored blobs and mark the damaged ones (D49)';

    public function handle(BlobScrubber $scrubber): int
    {
        $limit = $this->positiveOption('limit');
        $maxBytes = $this->positiveOption('max-bytes');
        $maxSeconds = $this->positiveOption('max-seconds');
        $scope = $this->option('scope');

        if ($limit === false || $maxBytes === false || $maxSeconds === false) {
            $this->components->error('--limit, --max-bytes and --max-seconds must be positive integers.');

            return self::INVALID;
        }

        if ($scope !== null && (!is_string($scope) || preg_match('/^(tenant|drive|off):.+$/D', $scope) !== 1)) {
            $this->components->error('--scope must be a scope key: tenant:<id>, drive:<id> or off:<id>.');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');

        $result = Tenancy::without(static fn (): array => $scrubber->scrub(
            limit: $limit,
            maxBytes: $maxBytes,
            maxSeconds: $maxSeconds,
            scope: $scope,
            dryRun: $dryRun,
        ));

        $this->components->info(
            "Scrubbed {$result['checked']} blobs ({$result['bytes']} bytes), found ".count($result['failures'])." damaged; stopped by {$result['stopped_by']}".
            ($dryRun ? ' (dry run, nothing recorded).' : '.'),
        );

        if ($result['failures'] !== []) {
            $this->components->warn('Damaged blobs: '.implode(', ', array_map(
                static fn (array $failure): string => $failure['id'].' ('.$failure['reason'].')',
                $result['failures'],
            )));
        }

        if ($result['unverified'] !== []) {
            $this->components->warn('Blobs that could not be read, left unmarked: '.implode(', ', $result['unverified']));
        }

        return $result['failures'] === [] && $result['unverified'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return int|false|null null: not given; false: not a positive integer */
    private function positiveOption(string $name): int|false|null
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        return is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/D', $value) === 1 ? (int) $value : false;
    }
}
