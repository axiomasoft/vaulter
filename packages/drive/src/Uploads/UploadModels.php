<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads;

use RuntimeException;
use Vaulter\Drive\Uploads\Models\UploadPart;
use Vaulter\Drive\Uploads\Models\UploadSession;
use Vaulter\Support\Config;

/**
 * Typed resolvers for the swappable resumable-upload models (P2 #5 / ADR-0015). Core's
 * Support\Config returns an opaque `class-string<Model>` for these (it must not name a
 * vaulter-drive class); this package OWNS UploadSession/UploadPart, so it narrows the
 * configured class back to its concrete type here — a real guard (a misconfigured host
 * class that does not extend the base fails loudly), not a phpdoc cast.
 */
final class UploadModels
{
    /**
     * @return class-string<UploadSession>
     */
    public static function session(): string
    {
        $class = Config::uploadSessionModel();

        if (!is_a($class, UploadSession::class, true)) {
            throw new RuntimeException(
                "Configured vaulter.models.upload_session [{$class}] must extend ".UploadSession::class.'.',
            );
        }

        return $class;
    }

    /**
     * @return class-string<UploadPart>
     */
    public static function part(): string
    {
        $class = Config::uploadPartModel();

        if (!is_a($class, UploadPart::class, true)) {
            throw new RuntimeException(
                "Configured vaulter.models.upload_part [{$class}] must extend ".UploadPart::class.'.',
            );
        }

        return $class;
    }
}
