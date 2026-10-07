<?php

declare(strict_types=1);

namespace Vaulter\Drive\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Vaulter\Contracts\Cas\ContentScanner;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Content\ScanBackfillReconciler;
use Vaulter\Drive\Content\ScanWorkReconciler;
use Vaulter\Drive\Content\UploadCleanupReconciler;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\VaulterManager;

final class ReconcileScanWorkCommand extends Command
{
    private bool $withinMaintenanceScope = false;

    protected $signature = 'vaulter:scan-reconcile {--tenant=} {--drive=} {--all-tenants} {--reason=} {--limit=100} {--max-seconds=30} {--dry-run} {--json}';

    protected $description = 'Recover bounded scan intents, backfill discovery and committed upload cleanup.';

    public function handle(ScanWorkReconciler $work, ScanBackfillReconciler $backfill, UploadCleanupReconciler $cleanup,
        DriveConfig $config): int
    {
        $started = microtime(true);
        try {
            $scope = RescanFileRevisionsCommand::scope($this);
            $config->validateScanWork(requireBroker: app()->bound(ContentScanner::class));
            if ($scope['all'] && !$this->withinMaintenanceScope) {
                $this->withinMaintenanceScope = true;
                try {
                    $result = app(VaulterManager::class)->withoutTenancy(fn (): int => $this->handle($work, $backfill, $cleanup, $config));
                    if (!is_int($result)) {
                        throw new InvalidArgumentException('Maintenance context returned an invalid result.');
                    }

                    return $result;
                } finally {
                    $this->withinMaintenanceScope = false;
                }
            }
            $seconds = filter_var($this->option('max-seconds'), FILTER_VALIDATE_INT);
            if ($seconds === false || $seconds < 1 || $seconds > 60) {
                throw new InvalidArgumentException('Invalid time bound.');
            }
            $ids = RescanFileRevisionsCommand::drives($scope)->select('id')->reorder();
            $dryRun = (bool) $this->option('dry-run');
            $out = RescanFileRevisionsCommand::summary('vaulter:scan-reconcile', $dryRun);
            $result = $backfill->expand($scope['limit'], $seconds, $dryRun, $ids);
            $stalled = $result['stalled_configuration'];
            $out = array_replace($out, $result);
            $remaining = $seconds - (int) (microtime(true) - $started);
            if ($remaining > 0) {
                $result = $work->reconcile($scope['limit'], $remaining, $dryRun, $ids);
                $out['stalled_configuration'] = $stalled + $result['stalled_configuration'];
                unset($result['stalled_configuration']);
                $out = array_replace($out, $result);
            }
            $remaining = $seconds - (int) (microtime(true) - $started);
            if ($remaining > 0) {
                $out = array_replace($out, $cleanup->reconcile($config->scanWork('cleanup_batch'), $remaining, $dryRun, $ids));
            }
            $out['elapsed_ms'] = (int) ((microtime(true) - $started) * 1000);
            $this->line(json_encode($out, JSON_THROW_ON_ERROR));

            return 0;
        } catch (InvalidConfigurationException $exception) {
            $this->error('Invalid scan queue configuration: '.$exception->getMessage());

            return 2;
        } catch (InvalidArgumentException) {
            $this->error('Invalid scan maintenance scope or bounds.');

            return 2;
        } catch (\Throwable) {
            $this->error('Scan reconciliation could not complete safely.');

            return 1;
        }
    }
}
