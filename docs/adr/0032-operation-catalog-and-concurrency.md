# ADR-0032 — The operation catalogue and aggregate concurrency

**Status:** accepted — builds on [ADR-0028](/adr/0028-extension-slots-and-operation-pipeline) and
[ADR-0029](/adr/0029-idempotency)

## Context

Idempotency and optimistic concurrency worked where somebody had remembered to add them: folder create and
rename took a key, a node had a `sequence`, a document a `state_version`. A new write had no place that asked
"how is a retry of this safe, and how is a lost update refused?". Shares, grants, drives, comments and webhook
endpoints had no version of their own: a compare-and-set on them would have hidden behind the sequence of the
node, which forbids the very separation of validators that [concurrency](/reference/concurrency) rests on. A
secret returned by a write (a share token, a signing secret) would have sat in plaintext in a receipt for 24
hours. System purges (retention, drive purge) and the lifecycle of a drive bypassed the pipeline altogether.

## Decision

**1. A catalogue of operation policies.** Each module contributes an `OperationPolicySource` (an SPI of the
kernel, registered with `ModuleBuilder::operations()` like problems and events). It maps an operation name
(`OperationSpec::$name`) to an `OperationPolicy`: the `aggregate` it changes, a `ConcurrencyPolicy`
(`if_match`, `append`, `natural`, `state_machine`, `generation`, `row_identity`), an `IdempotencyPolicy`
(`receipt`, `natural`, `conditional`, `protocol`) and the REST route names. The `OperationCatalog` is built from
the active modules; the same name declared by two installed modules is an invalid module graph.
`vaulter:operations [--json]` prints it, and [the operation catalogue](/reference/operations) is generated from
that output.

**2. A mutating operation outside the catalogue does not run.** `OperationRunner::write()` throws
`UnknownOperationException` (a `LogicException`, a developer's error) before the claim and before the transaction
when `OperationSpec::$mutating` is true and the name is unknown or belongs to another module; nested operations
are checked the same way. The runtime refusal makes the registry complete for the PHP API, and the route tests of
each package (`OperationPolicyCoverageTest`) make it complete for REST.

**3. A version per aggregate.** Every aggregate changed by an `if_match` operation has a column of its own:
`v_nodes.access_version` (grants and inheritance; `sequence` does not move), `v_node_shares.version`,
`v_drives.version`, `v_node_comments.version`, `v_webhook_endpoints.version`. The change is
`UPDATE … SET version = version + 1 WHERE id = ? AND version = ?` under the lock of the aggregate, in the
transaction of the operation; zero rows is `412`. The resource body carries the tag as `etag`, and the `ETag`
header repeats it.

**4. `If-Match` is required.** A mutating route of an existing aggregate answers `428 precondition_required`
without `If-Match` or with `*`, and `412` for a foreign tag. Nodes and documents keep `node_sequence_mismatch`
and `document_state_mismatch`; the new aggregates answer one core code, `stale_write` (retryable
`after_refetch`, receipt `cacheable`). Creations (`append`) take no `If-Match`.

**5. Secrets in receipts are encrypted.** The receipts of share create/rotate and webhook endpoint
register/rotate-secret are encoded with `Crypt::encryptString`. A replay returns the same secret only within the
same scope digest (the actor is part of it) and after the current authorization.

**6. System paths go through the runner.** Drive archive, unarchive and delete run as `drive.drives.*`; the
retention purge and the drive purge run one root per operation as `drive.trash.purge` with a system context, so
the pipeline, its events and its middleware see every write.

**7. Integrity is a media state.** `vaulter:blobs:scrub` checks stored bytes against size and sha256 within a
sample and a budget, marks `integrity_failed_at`, and never deletes. The drive refuses to hand out a blob that is
not intact. See [Blob integrity](/guides/blob-integrity).

## Replacement and cost

The catalogue **replaces** the convention "remember to add a key and a version" with a registry the kernel
enforces. It is a new concept, and it costs:

- one declaration per operation and one class per module (`OperationPolicySource`); a new write cannot ship
  without it;
- five version columns and the extra `UPDATE … WHERE version = ?` on writes that previously had none;
- a client must now send `If-Match` on mutating routes that used to accept a bare request, and read the `etag`
  of the resource first;
- a coverage test per package that reads routes and sources;
- `APP_KEY` becomes part of the readability of secret receipts.

It adds no router, no bus and no second pipeline: the catalogue is read by the runner that already exists.
Policies are descriptions plus an enforced presence, not behaviour; each guarantee is still implemented where it
was (the claim, the codec, the compare-and-set).

## Consequences

- Every mutating write has a stated concurrency and idempotency policy; reviewing a new one starts at its row in
  the catalogue.
- Lost updates on shares, grants, drives, comments and endpoints are refused with `412 stale_write`, and a retry
  under the same key repeats the refusal instead of racing.
- The identities involved (command `vaulter:operations`, code `stale_write`, the columns, the routes' headers) are
  changed before 1.0 without a transition and are recorded in the P2 inventory.
- Long or asynchronous operations remain out of scope, as in ADR-0029.
