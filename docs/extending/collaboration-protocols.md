# Collaboration protocols

Public documents owns `POST documents/{node}/collaboration/session` and
`Documents::in($drive)->collaborationSession($node)`. The public client consumes a neutral
session; a host connector owns protocol transport. Yjs is provided by the commercial add-on
`axioma-studio/vaulter-collaboration`.

## Register an adapter

Implement `Vaulter\Documents\Contracts\CollaborationProtocolDriver`:
`protocol(): string`, `version(): string`,
`supportsState(string $documentType, string $stateFormat, int $schemaVersion, bool $hasCheckpoint, CollaborationMode $mode): bool`
and `issueSession(CollaborationGrant $grant): CollaborationSession`.
The compatibility predicate is pure: no I/O, conversion or authorization.
Register the driver with `ModuleBuilder::slot(CollaborationProtocolDriver::class, AcmeDriver::class, 'acme.protocol', key: 'acme-ot')`.
Register `PendingStateDrainer` under the same protocol key and the document type under its type key.
Use a sealed module graph; do not build another registry. The type's `SupportsCollaboration`
returns the protocol and persisted state format independently.

## Grant and verification

This is the fixture's read grant shape; production grants come from `CollaborationAccessPolicy`,
not caller-supplied authority. `DocumentsConfig::collaborationSessionTtl()` bounds expiry to 1–300 seconds.

<!-- snippet: host -->

```php
use Vaulter\Documents\Data\Collaboration\CollaborationGrant;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\ValueObjects\Access\ActorRef;

$read = new CollaborationGrant(
    nodeId: '42', driveId: '7', tenant: null,
    principal: new ActorRef('staff', '42'), mode: CollaborationMode::Read,
    generation: 3, schemaVersion: 1, documentType: 'acme/ot',
    protocol: 'acme-ot', stateFormat: 'acme-ot-v1', notAfter: time() + 300,
);
```

`CollaborationSession` contains protocol, version, nullable endpoint, token, expiresAt, mode,
nodeId, generation, schemaVersion and nonsecret scalar options. `assertGrant($grant, $version)`
checks that the session cannot expand the grant. Read mode must remain read.
The Acme fixture issues random opaque credentials, stores the grant and verifies through
`AcmeOtTokenVerifier::verify(string $token): array`; invalid and expired tokens fail.
The normalized result carries typed principal, tenant, node/drive, protocol, state_format,
generation, schema_version, mode and expires_at. Never reconstruct these from unverified input.
A production backend must also recheck `CollaborationAccessPolicy::evaluateReference()` on
user calls using the verified actor/reference and compare the full stored binding under lock.
The local fixture log demonstrates persistence/fencing, not a complete network security deployment.
Revocation takes effect at the next live backend policy check; token expiry bounds detached
credentials. External disconnect/control delivery is best effort. Service checkpointing of
already ACKed records remains possible after user revocation.

## Adapter tuples and limits

| Adapter | Wire | Stored format | Schema/checkpoint |
|---|---|---|---|
| Acme fixture (`acme/ot`) | `acme-ot/1` | `acme-ot-v1` | 1; JSON authoritative, checkpoint may stay null |
| Yjs rich text/markdown | `yjs/2` | `yjs-update-v1` | type schema; null only before seed, initialized checkpoint required |

Acme uses `OpaqueJsonCodec(maxSizeBytes: 4096)` in its fixture. General document size comes
from the codec's `maxSizeBytes()` or `DocumentsConfig::maxContentSizeKb()`. Transport limits
are adapter-owned: the Yjs configuration exposes `updateBytes()`, `requestBytes()`,
`maxStateBytes()` and `checkpointRequestBytes()`; these are not universal Acme frame limits.
A network adapter must enforce its own frame/update limits before buffering and projection limits
before checkpoint publication. Do not infer compatibility from wire version alone.

## Persistence, drain and restore

The executable examples are `packages/documents/tests/Collaboration/AcmeOtProtocolContractTest.php`
and `SecondProtocolTest.php`, with implementations in `tests/Fixtures/Collaboration` of that package.
Use `CollaborationProtocolContractTests::assertCollaborationProtocolContract($driver, $read, $write, $verifier)`
with the actual backend verifier, not a token decoder invented for the test.

The lifecycle creates an Acme document and session, calls `AcmeFixtureLog::append($token, $content)`,
flushes/fsyncs the record and calls `markPending($locked, $binding, $sequence)` before ACK.
Within `VaulterDatabase::transaction()`, obtain `$locked = $state->lock($nodeId)` and publish with
`new CheckpointPublication($marker->binding, $locked->stateVersion, $marker->throughSeq, null, $content)`.
Inspect `CheckpointOutcome::published`; a foreign protocol or stale CAS cannot clear the marker.
`AcmePendingStateDrainer::drain($marker)` prepares the projection outside the transaction, then
locks and publishes. Pending reads drain before returning the authoritative projection.

Ordinary JSON replacement fences the old generation. `restore($node, $revisionId)` restores
revision content/binding into a fresh epoch; request a second session afterwards. The old token
cannot append even if content is equal. Transition preparation requires a concrete converter;
there is no generic conversion registry. Pending queries filter by saved protocol; a missing
drainer blocks purge. Locks follow tree → node → document, then transport rows. Never merge
or wait for a remote backend while holding them.

On reconnect, obtain a fresh authorized session and resume only within its generation. ACK means
the adapter's durable journal boundary, not merely WebSocket delivery; checkpoint visibility may
lag ACK. Do not replay an old generation after restore. Unsupported read compatibility makes the
room unavailable; unsupported write compatibility can yield read-only. JSON remains accessible
under ordinary read permissions. See the [problem catalog](/reference/errors) for REST errors;
release-specific wire/state compatibility belongs to each adapter's compatibility matrix.

## Client dispatch

<!-- snippet: host -->

```ts
// `session` is the neutral REST response; this host selects its Acme connector.
if (session.protocol !== 'acme-ot' || session.version !== 'acme-ot/1'
    || session.endpoint !== null || session.options.document !== session.node_id
    || session.expires_at <= Math.floor(Date.now() / 1000)) {
  throw new Error('Unsupported collaboration session')
}
// Pass session.token and session.mode to the host connector; never log the token.
```

A different adapter may require an endpoint. Validate its endpoint against the host's allowed
origins, protocol version and options schema before connecting. Never fall back to a different
protocol when validation fails. See [Document types](/extending/document-types) and
[Viewers](/extending/viewers) for the type and UI boundaries.

See the [compatibility reference](/reference/compatibility).
