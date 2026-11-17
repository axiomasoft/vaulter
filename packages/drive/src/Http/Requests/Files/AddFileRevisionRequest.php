<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests\Files;

use Illuminate\Http\UploadedFile;
use Packman\Attributes\Internal;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Http\Requests\VaulterFormRequest;

/** POST files/{node}/revisions: the multipart bytes of the file's next revision. */
#[Internal]
final class AddFileRevisionRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(DriveOptions $driveConfig): array
    {
        return [
            'file' => UploadFileRequest::fileRules($driveConfig),
        ];
    }

    public function uploadedFile(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }
}
