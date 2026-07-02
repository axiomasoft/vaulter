<?php

declare(strict_types=1);

use Vaulter\Drive\Uploads\Models\UploadPart;
use Vaulter\Drive\Uploads\Models\UploadSession;

/*
|--------------------------------------------------------------------------
| Vaulter Drive — swappable-model defaults (P2 #5 / ADR-0015, T9)
|--------------------------------------------------------------------------
| The resumable-upload models are OWNED by vaulter-drive: core no longer ships
| their class defaults (Support\Config guard-throws when the key is absent). The
| provider merges this array under the existing `vaulter.models` key so a host's
| config('vaulter.models.upload_session') resolves without any core change. The
| v_upload_sessions / v_upload_parts SCHEMA stays in core (schema-owner, T2).
*/

return [
    'upload_session' => UploadSession::class,
    'upload_part' => UploadPart::class,
];
