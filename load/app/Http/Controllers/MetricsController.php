<?php

declare(strict_types=1);

namespace Vaulter\Load\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Vaulter\Load\Observability\RedisMetricsRegistry;

final readonly class MetricsController
{
    public function __construct(
        private RedisMetricsRegistry $registry,
    ) {}

    public function index(Request $request): Response|JsonResponse
    {
        $requested = $request->query('run_id');
        $runId = is_string($requested) && $requested !== '' ? $requested : $this->registry->activeRunId();

        if ($runId === null) {
            return response()->json(['message' => 'No metrics run is available.'], 404);
        }

        try {
            $body = $this->registry->exposition($runId);
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'Invalid metrics run_id.'], 422);
        }

        if ($body === null) {
            return response()->json(['message' => 'Metrics run not found.'], 404);
        }

        return response($body, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $requested = $request->input('run_id');
        $runId = is_string($requested) && $requested !== '' ? $requested : (string) Str::uuid();
        $ttl = $request->input('ttl_seconds', config('load.metrics.ttl_seconds', RedisMetricsRegistry::DEFAULT_TTL_SECONDS));

        if (!is_numeric($ttl)) {
            return response()->json(['message' => 'ttl_seconds must be numeric.'], 422);
        }

        try {
            $this->registry->openRun($runId, (int) $ttl);
        } catch (InvalidArgumentException $exception) {
            $status = str_contains($exception->getMessage(), 'already exists') ? 409 : 422;

            return response()->json(['message' => $exception->getMessage()], $status);
        }

        return response()->json([
            'run_id' => $runId,
            'families' => count($this->registry->familyNames()),
            'state' => 'open',
        ], 201);
    }

    public function finalize(Request $request): JsonResponse
    {
        $requested = $request->input('run_id');
        $runId = is_string($requested) && $requested !== '' ? $requested : $this->registry->activeRunId();

        if ($runId === null) {
            return response()->json(['message' => 'No metrics run is available.'], 404);
        }

        try {
            $finalized = $this->registry->finalizeRun($runId);
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'Invalid metrics run_id.'], 422);
        }

        if (!$finalized) {
            return response()->json(['message' => 'Metrics run not found.'], 404);
        }

        return response()->json(['run_id' => $runId, 'state' => 'finalized']);
    }
}
