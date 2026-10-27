# ADR-0034 — Document SPI v2 and collaboration protocols

Status: Accepted. Amends [ADR-0031](/adr/0031-collaboration-package-and-durability).

## Context

Document types must work without a realtime backend, and a second protocol must not depend on
Yjs storage or private implementation classes. Independent consumers need a small typed boundary.

## Decision

Keep one `DocumentType` SPI with document-owned `DocumentViewer` and `DocumentFeatures` values
and optional capabilities. `DocumentPresenter` adapts these values to Drive presentation.
Local editing remains available without a protocol driver; `DocumentFeatures::readOnly()`
retains preview while disabling edits. Unknown types retain their content and use an unsupported viewer.

Use the existing sealed module handler slots for `CollaborationProtocolDriver` and
`PendingStateDrainer`, keyed by protocol. Public documents owns session REST, the operation and
`CollaborationAccessPolicy`; clients dispatch by the returned protocol/version/options.
Drivers encode an already authorized `CollaborationGrant`, with typed `ActorRef` and expiry;
they do not make access decisions. Backend user calls verify credentials and recheck the same
live policy. No secrets belong in session options.

The stored `(protocol, state_format, schema_version, generation)` binding is immutable within
a generation. Wire version is independent of persisted format. `CollaborativeState` is the
single storage/CAS port; its lock value and typed publication/transition values are the consumer
boundary. Public consumers do not reach into document models, queries or concrete state writers.
Locks are T → N → D within `VaulterDatabase::transaction()`; network work and merging stay outside.
Pending drain routes by the saved marker protocol. Unknown adapters preserve pending state and
block destructive purge. A service checkpoint may finish previously acknowledged edits after
revocation; it cannot authorize new user appends.

Transitions drain the source, prepare conversion outside locks, then recheck the full binding,
state version and absence of pending under locks. JSON, checkpoint, schema and binding change
atomically with a new epoch. Restore uses revision metadata and a fresh epoch; equal JSON does
not bypass fencing. No converter means refusal, not an implicit reset. Nullable checkpoints
are adapter-specific: Acme is stateless; Yjs null is a pre-seed condition, not a valid replacement
for an initialized Yjs checkpoint.

## Alternatives considered

- Four mandatory type interfaces and new schema/type-key wrappers add ceremony without a second
  consumer; keep the existing SPI and optional capabilities.
- A separate mutable `CollaborationProtocolRegistry` duplicates sealed typed handler slots and
  their duplicate-key checks.
- Renaming JWT claims would keep a token-specific dependency. The boundary is an authorized grant
  and a neutral session; opaque credentials are valid too.
- A second persistence SPI, generic converter registry or resource/operation framework duplicates
  existing state, module and operation ports. Future consumers must justify an additional seam.

## Consequences

Adapter authors supply compatibility checks, credential verification, drain and contract-kit
coverage. Read-only sessions never gain write authority. Missing old-format support makes the
room unavailable even for read; JSON projection access follows normal document permissions.
See [Collaboration protocols](/extending/collaboration-protocols) for the Acme fixture lifecycle.
The dependency guards enforce specific supported boundaries; they are not a universal AST security sandbox.
