<?php

declare(strict_types=1);

namespace Vaulter\Load\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Vaulter\Load\Health\LoadReadinessRunner;

final class ReadyController
{
    public function __construct(
        private readonly LoadReadinessRunner $runner,
    ) {}

    public function __invoke(): JsonResponse
    {
        $checks = $this->runner->checks();

        $ready = $checks['db'] && $checks['s3'] && $checks['queue'];

        return response()->json([
            'status' => $ready ? 'ready' : 'not_ready',
            'checks' => [
                'db' => ['ok' => $checks['db']],
                's3' => ['ok' => $checks['s3']],
                'queue' => ['ok' => $checks['queue']],
            ],
        ], $ready ? 200 : 503);
    }
}
