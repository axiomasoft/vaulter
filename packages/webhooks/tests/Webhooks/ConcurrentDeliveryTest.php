<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Webhooks\Configuration\WebhooksConfig;
use Vaulter\Webhooks\Contracts\OutboundUrlGuard;
use Vaulter\Webhooks\Jobs\DeliverWebhook;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;
use Vaulter\Webhooks\WebhookSigner;

it('excludes a duplicate worker while another process is posting the same delivery', function (string $outcome): void {
    $connection = DB::connection();
    expect(str_ends_with($connection->getDatabaseName(), '_test'))->toBeTrue();

    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint);
    $deliveryId = (string) $delivery->getKey();
    $endpointId = $endpoint->getKey();

    // Make the fixture visible to two independent connections. Restore the
    // RefreshDatabase transaction after deleting only this test's committed rows.
    $connection->commit();
    DB::disconnect();
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($sockets)->not->toBeFalse();
    [$parent, $child] = $sockets;
    stream_set_timeout($parent, 10);
    stream_set_timeout($child, 10);
    $pid = pcntl_fork();

    if ($pid === 0) {
        fclose($parent);

        try {
            Http::fake(function () use ($child, $outcome) {
                fwrite($child, "posting\n");

                if (fgets($child) !== "release\n") {
                    throw new RuntimeException('Concurrent test did not release the HTTP barrier');
                }

                if ($outcome === 'transport') {
                    throw new ConnectionException('test connection refused');
                }

                return Http::response('', (int) $outcome);
            });
            new DeliverWebhook($deliveryId)->handle(signer: app(WebhookSigner::class), urlGuard: app(OutboundUrlGuard::class), webhooksConfig: app(WebhooksConfig::class), database: app(VaulterDatabase::class));
            fwrite($child, "done\n");
            DB::disconnect();
            fclose($child);
            // Replace the forked interpreter so PHPUnit's inherited shutdown
            // hooks cannot run fixture cleanup or continue the parent test suite.
            pcntl_exec(PHP_BINARY, ['-r', 'exit(0);']);
            exit(1);
        } catch (Throwable $error) {
            fwrite($child, 'error: '.$error->getMessage()."\n");
            DB::disconnect();
            fclose($child);
            pcntl_exec(PHP_BINARY, ['-r', 'exit(1);']);
            exit(1);
        }
    }

    fclose($child);
    $released = false;
    $reaped = false;

    try {
        expect($pid)->toBeGreaterThan(0);
        // The first worker has acquired its durable claim and reached HTTP. Its
        // response is held until the second worker has completed handle().
        expect(fgets($parent))->toBe("posting\n");
        Http::fake(['*' => Http::response('', 200)]);
        new DeliverWebhook($deliveryId)->handle(signer: app(WebhookSigner::class), urlGuard: app(OutboundUrlGuard::class), webhooksConfig: app(WebhooksConfig::class), database: app(VaulterDatabase::class));
        Http::assertNothingSent();
        expect(WebhookDelivery::query()->findOrFail($deliveryId)->claim_token)->not->toBeNull();

        fwrite($parent, "release\n");
        $released = true;
        expect(fgets($parent))->toBe("done\n");
        pcntl_waitpid($pid, $status);
        $reaped = true;
        expect(pcntl_wexitstatus($status))->toBe(0);

        $fresh = WebhookDelivery::query()->findOrFail($deliveryId);
        expect($fresh->status)->toBe($outcome === '200' ? 'delivered' : 'failed')
            ->and($fresh->attempts)->toBe(1)
            ->and($fresh->claim_token)->toBeNull()
            ->and($fresh->claim_until)->toBeNull();
        // Only the worker that held the lease accounts the attempt on the endpoint.
        $settled = $endpoint->newQuery()->findOrFail($endpointId);
        expect($settled->failure_count)->toBe($outcome === '200' ? 0 : 1)
            ->and($settled->failing_since === null)->toBe($outcome === '200');
    } finally {
        if (!$released) {
            fwrite($parent, "release\n");
            fgets($parent);
        }

        if ($pid > 0 && !$reaped) {
            pcntl_waitpid($pid, $status);
        }

        fclose($parent);

        $delivery->newQuery()->whereKey($deliveryId)->delete();
        $endpoint->newQuery()->whereKey($endpointId)->delete();
        DB::connection()->beginTransaction();
    }
})->with(['200', '500', 'transport'])
    ->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'Requires isolated PostgreSQL connections')
    ->skip(fn (): bool => !function_exists('pcntl_fork'), 'Requires pcntl for the two-worker barrier');
