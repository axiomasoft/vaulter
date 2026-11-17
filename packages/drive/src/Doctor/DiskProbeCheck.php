<?php

declare(strict_types=1);

namespace Vaulter\Drive\Doctor;

use Illuminate\Support\Str;
use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use RuntimeException;
use Throwable;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Media\Configuration\MediaOptions;
use Vaulter\Media\Contracts\Cas\StorageDriver;

/**
 * The one check that writes: it puts a test object on each disk, reads it back and deletes it,
 * and only when the owner asked for it (--probe-storage, or the production profile).
 */
#[Internal]
final readonly class DiskProbeCheck implements DoctorCheck
{
    public function __construct(private MediaOptions $options, private ProfileRegistry $profiles, private StorageDriver $storage) {}

    public function key(): string
    {
        return 'media.disk.probe';
    }

    public function title(): string
    {
        return 'Storage disks';
    }

    public function run(DoctorContext $context): array
    {
        $disks = DiskSet::of($this->options, $this->profiles);

        if (!$context->mayProbeStorage()) {
            return [DoctorFinding::ok(
                code: 'media.disk.probe_skipped',
                message: 'Storage disks not evaluated; pass --probe-storage.',
                evidence: ['skipped' => true, 'disks' => implode(', ', $disks)],
            )];
        }

        return array_map($this->probe(...), $disks);
    }

    private function probe(string $disk): DoctorFinding
    {
        $key = '.vaulter-doctor/'.Str::ulid();
        $failure = $this->exercise($key, $disk);
        $removed = $this->remove($key, $disk);

        if ($failure !== null) {
            return $failure;
        }
        if (!$removed) {
            // The object stays on the disk: say where, so the owner can delete it.
            return DoctorFinding::error(
                code: 'media.disk.probe_delete_failed',
                message: 'Disk "'.$disk.'" accepted a test object but refused to delete it.',
                remediation: 'grant delete permission on the disk and remove the object named in the evidence',
                evidence: ['disk' => $disk, 'object' => $key],
            );
        }

        return DoctorFinding::ok(code: 'media.disk.probe_ok', message: 'Disk "'.$disk.'" accepts, returns and deletes an object.', evidence: ['disk' => $disk]);
    }

    /** Writes and reads the object back; null when both worked. */
    private function exercise(string $key, string $disk): ?DoctorFinding
    {
        $stage = 'write';
        try {
            $stream = fopen('php://temp', 'r+');
            if ($stream === false) {
                throw new RuntimeException('No temporary stream.');
            }
            fwrite($stream, 'vaulter-doctor');
            rewind($stream);
            $this->storage->put($key, $stream, $disk);
            fclose($stream);

            $stage = 'read';
            $read = $this->storage->get($key, $disk);
            $content = is_resource($read) ? stream_get_contents($read) : false;
            if (is_resource($read)) {
                fclose($read);
            }
            if ($content !== 'vaulter-doctor') {
                return DoctorFinding::error(
                    code: 'media.disk.probe_mismatch',
                    message: 'Disk "'.$disk.'" returned other bytes than were written.',
                    remediation: 'check the disk configuration',
                    evidence: ['disk' => $disk],
                );
            }
        } catch (Throwable $exception) {
            return DoctorFinding::error(
                code: 'media.disk.probe_failed',
                message: 'Disk "'.$disk.'" failed to '.$stage.' a test object.',
                remediation: 'check the disk credentials, permissions and bucket policy',
                evidence: ['disk' => $disk, 'stage' => $stage, 'exception' => $exception::class],
            );
        }

        return null;
    }

    /** Whether the object is gone. */
    private function remove(string $key, string $disk): bool
    {
        try {
            $this->storage->delete($key, $disk);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
