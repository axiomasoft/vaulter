<?php

declare(strict_types=1);

namespace Vaulter\Load\Observability;

use Illuminate\Support\Facades\Http;
use Vaulter\Contracts\Observability\MetricsRecorder;

/**
 * Absolute active collaboration room count from the Hocuspocus hub `/stats` (P8.3).
 */
final class CollaborationRoomMetrics
{
    public function __construct(
        private readonly MetricsRecorder $metrics,
    ) {}

    public function observeActiveRooms(): void
    {
        $url = rtrim((string) env('VAULTER_COLLAB_STATS_URL', 'http://hocuspocus:1235/stats'), '/');

        $response = Http::timeout(2)->get($url);

        if (!$response->successful()) {
            return;
        }

        /** @var array{rooms?: int|float|string, connections?: int|float|string}|null $payload */
        $payload = $response->json();

        if (!is_array($payload) || !array_key_exists('rooms', $payload)) {
            return;
        }

        $rooms = $payload['rooms'];

        if (!is_int($rooms) && !is_float($rooms) && !is_string($rooms)) {
            return;
        }

        $this->metrics->gauge(
            name: 'vaulter_collab_room_active',
            value: (float) $rooms,
        );
    }
}
