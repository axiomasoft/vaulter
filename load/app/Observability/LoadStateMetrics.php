<?php

declare(strict_types=1);

namespace Vaulter\Load\Observability;

use Illuminate\Support\Facades\DB;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Support\Config;

/**
 * Host-side absolute gauges for load SUT state snapshots (P8.3).
 */
final class LoadStateMetrics
{
    public function __construct(
        private readonly MetricsRecorder $metrics,
    ) {}

    public function observeUploadSessions(): void
    {
        $table = Config::table('upload_sessions');
        $openStatuses = [
            UploadStatus::Initiated->value,
            UploadStatus::Uploading->value,
            UploadStatus::Assembled->value,
        ];

        $rows = DB::table($table)
            ->select('protocol', DB::raw('count(*) as aggregate'))
            ->whereIn('status', $openStatuses)
            ->groupBy('protocol')
            ->get();

        foreach ($rows as $row) {
            $protocol = (string) $row->protocol;
            $count = (int) $row->aggregate;

            $this->metrics->gauge(
                name: 'vaulter_upload_session_active',
                value: $count,
                labels: ['protocol' => $protocol],
            );
        }

        $knownProtocols = UploadProtocol::values();

        foreach ($knownProtocols as $protocol) {
            if ($rows->contains(static fn ($row): bool => (string) $row->protocol === $protocol)) {
                continue;
            }

            $this->metrics->gauge(
                name: 'vaulter_upload_session_active',
                value: 0,
                labels: ['protocol' => $protocol],
            );
        }
    }

    public function observeOutboxBacklog(): void
    {
        if (!Config::featureEnabled('reliability')) {
            $this->metrics->gauge(name: 'vaulter_outbox_backlog', value: 0);

            return;
        }

        $table = Config::table('outbox');
        $pending = (int) DB::table($table)->where('status', 'pending')->count();

        $this->metrics->gauge(name: 'vaulter_outbox_backlog', value: $pending);
    }
}
