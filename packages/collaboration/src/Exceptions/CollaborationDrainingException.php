<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Exceptions;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * The collaboration module is draining (C10): `vaulter:collaboration:drain` set the draining flag, so the log takes no
 * new update while the hub checkpoints the pending documents before the module is switched off. Nothing was written;
 * the hub keeps the update in its retry queue, and it is accepted again once `--release` removed the flag.
 */
#[Api]
final class CollaborationDrainingException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    #[Internal]
    public function __construct()
    {
        parent::__construct(sprintf(
            '[Vaulter] The collaboration log takes no updates while it is drained (vaulter:collaboration:drain); retry later. Docs: %s',
            self::docs('collaboration-draining'),
        ));
    }

    public function problemCode(): string
    {
        return 'collaboration_draining';
    }

    public function httpStatus(): int
    {
        return 503;
    }
}
