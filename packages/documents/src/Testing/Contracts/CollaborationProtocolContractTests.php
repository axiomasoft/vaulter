<?php

declare(strict_types=1);

namespace Vaulter\Documents\Testing\Contracts;

use Closure;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Assert;
use Throwable;
use Vaulter\Attributes\Api;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\Data\Collaboration\CollaborationGrant;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * Run inside the host test application with a fixed Carbon clock. The callback must
 * normalize the adapter's real verifier result, never the expected grant. Adapters
 * additionally assert their own room/options relationship and checkpoint invariants.
 */
// @phpstan-ignore trait.unused (Public host testing trait; consumed by Pest outside src, like DocumentTypeContractTests.)
#[Api]
trait CollaborationProtocolContractTests
{
    public function assertCollaborationSessionContract(CollaborationProtocolDriver $driver, CollaborationGrant $grant, CollaborationSession $session): void
    {
        Assert::assertSame($grant->protocol, $driver->protocol(), 'slot key must match driver protocol');
        $session->assertGrant($grant, $driver->version());
        Assert::assertSame($grant->nodeId, $session->toArray()['node_id']);
    }

    /**
     * @param Closure(string): array{principal: ActorRef, tenant: ?string, node_id: string, drive_id: string, protocol: string, state_format: string, generation: int, schema_version: int, mode: CollaborationMode, expires_at: int} $verify
     */
    public function assertCollaborationProtocolContract(CollaborationProtocolDriver $driver, CollaborationGrant $readGrant, CollaborationGrant $writeGrant, Closure $verify): void
    {
        Assert::assertSame(CollaborationMode::Read, $readGrant->mode);
        Assert::assertSame(CollaborationMode::Write, $writeGrant->mode);
        Assert::assertMatchesRegularExpression('/^[a-z][a-z0-9-]*$/D', $driver->protocol());
        Assert::assertMatchesRegularExpression('/^'.preg_quote($driver->protocol(), '/').'\/[1-9][0-9]*$/D', $driver->version());
        foreach ([$readGrant, $writeGrant] as $grant) {
            Assert::assertTrue($driver->supportsState($grant->documentType, $grant->stateFormat, $grant->schemaVersion, false, $grant->mode), 'Selected stored binding must be supported.');
            $keys = null;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $session = $driver->issueSession($grant);
                $this->assertCollaborationSessionContract($driver, $grant, $session);
                Assert::assertGreaterThan(Carbon::now()->getTimestamp(), $session->expiresAt);
                Assert::assertSame(['protocol', 'version', 'endpoint', 'token', 'expires_at', 'mode', 'node_id', 'generation', 'schema_version', 'options'], array_keys($session->toArray()));
                json_encode($session->options, JSON_THROW_ON_ERROR);
                $currentKeys = array_keys($session->options);
                sort($currentKeys);
                if ($keys !== null) {
                    Assert::assertSame($keys, $currentKeys, 'Option structure must be deterministic.');
                }
                $keys = $currentKeys;
                try {
                    $claims = $verify($session->token);
                } catch (Throwable) {
                    // Never include a token, verifier exception or complete claims in diagnostics.
                    Assert::fail('Credential verifier rejected the issued session.');
                }
                Assert::assertTrue($claims['principal']->type === $grant->principal->type
                    && (string) $claims['principal']->id === (string) $grant->principal->id
                    && $claims['principal']->reason === $grant->principal->reason, 'Verified credential principal differs.');
                foreach (['tenant' => $grant->tenant, 'node_id' => $grant->nodeId, 'drive_id' => $grant->driveId,
                    'protocol' => $grant->protocol, 'state_format' => $grant->stateFormat,
                    'generation' => $grant->generation, 'schema_version' => $grant->schemaVersion,
                    'mode' => $session->mode, 'expires_at' => $session->expiresAt] as $field => $expected) {
                    Assert::assertTrue($claims[$field] === $expected, 'Verified credential '.$field.' differs.');
                }
            }
        }
    }
}
