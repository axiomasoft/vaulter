<?php

declare(strict_types=1);

namespace VaulterRef\Support;

/**
 * Test seam: a named point where a check may kill the process, pause it on a barrier or inject a server-side
 * serialization failure. Production binds NoFaults. The points used are listed in plan/checks/atomic-mutation.
 */
interface FaultPoint
{
    public function reach(string $point, ?\VaulterRef\Database\Pg $db = null): void;
}
