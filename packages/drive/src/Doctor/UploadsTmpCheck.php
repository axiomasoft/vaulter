<?php

declare(strict_types=1);

namespace Vaulter\Drive\Doctor;

use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Drive\Configuration\DriveConfig;

final readonly class UploadsTmpCheck implements DoctorCheck
{
    public function __construct(private DriveConfig $config) {}

    public function key(): string
    {
        return 'drive.uploads.tmp';
    }

    public function title(): string
    {
        return 'Upload staging directory';
    }

    public function run(DoctorContext $context): array
    {
        $directory = sys_get_temp_dir();
        $maxFileSize = $this->config->maxFileSize();
        $free = @disk_free_space($directory);
        $evidence = ['writable' => is_writable($directory), 'free_bytes' => $free === false ? null : (int) $free, 'max_file_size' => $maxFileSize];

        if (!is_writable($directory)) {
            return [DoctorFinding::error(code: 'drive.uploads.tmp_not_writable', message: 'The temporary directory is not writable: resumable uploads cannot stage chunks.', hint: 'make sys_get_temp_dir() writable for the PHP user', evidence: $evidence)];
        }
        if ($free !== false && $free <= $maxFileSize) {
            return [DoctorFinding::error(code: 'drive.uploads.tmp_too_small', message: 'The temporary directory has less free space than the largest accepted file.', hint: 'free up space or lower vaulter-drive.uploads.max_file_size', evidence: $evidence)];
        }

        return [DoctorFinding::ok(code: 'drive.uploads.tmp_ok', message: 'The temporary directory is writable and larger than the largest accepted file.', evidence: $evidence)];
    }
}
