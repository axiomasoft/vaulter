---
title: Threat model
---

# Threat model

## Assets

Stored content (files, documents, revisions), the access decisions around it, tenant isolation, and the
integrity of the event stream that webhooks publish.

## Trust boundaries

| Boundary | Trust | Main control |
| --- | --- | --- |
| HTTP API v1 | caller is untrusted until authenticated | auth middleware of the host; every operation authorized per node ([Access model](/concepts/access-model)) |
| Protocol adapter | transport is trusted, its callers are not | verify principal, binding and generation; check the common policy on every write; fail closed on uncertainty |
| Webhook egress | the target URL is attacker-chosen | outbound URL guard: allowed schemes, blocked IP ranges, `allowed_ip_ranges`, response size cap |
| Uploaded bytes | content is untrusted | size/MIME policy, inspectors, durable malware-scan obligation |
| Direct download URL | a URL is a bearer credential | short TTL; `strict` mode as alternative ([Direct URL limits](/guides/operations/direct-url-limits)) |
| Tenant boundary | tenants must not see each other | tenant-scoped dedup and keys ([Tenancy](/concepts/tenancy)) |

## Threats and controls

- **Unauthorized read or write** — every handle acts for an actor; the permission driver decides, a grant can raise but a missing decision denies.
- **Cross-tenant disclosure through dedup** — identical bytes are never shared across tenants (default scope `tenant`; [ADR 0012](/adr/0012-dedup-scope)).
- **SSRF through webhooks** — the URL guard rejects non-allowed schemes and internal IP ranges at delivery time. The default blocked ranges also cover IPv4-embedding IPv6 (IPv4-compatible, NAT64, 6to4, Teredo), TEST-NET and benchmarking ranges; the production invariant checks them by binary CIDR coverage (a wider custom range is fine, a malformed one is a violation). Delivery never uses `HTTP(S)_PROXY`/`ALL_PROXY` (any case), never follows redirects, re-pins DNS on every attempt, does not decode `Content-Encoding`, and aborts a response body that streams past `max_response_bytes` even when `Content-Length` is absent or false. An explicit `allowed_ip_ranges` entry remains a host-owned override. Unit fixtures prove this wiring only; real libc/DNS/cURL/TLS behaviour is covered by owner qualification (P8.2).
- **Malicious file** — uploads are held until a scan verdict when scanning is required; quarantine is never released in bulk.
- **Lost or forged event** — events are written in the change's transaction; deliveries are signed and carry a stable `webhook-id`.
- **Leaked download URL** — bounded by TTL only; see the runbook.

## Upload controls → tests

Status: **fast** = sqlite test in the package suite; **server/stand** = needs real storage/DB races (P8.2 runbook); **pending** = no test mapped yet. The built-in multipart handler (`S3MultipartHandler`) proxies parts through the server; it is **not** native AWS presigned multipart, so native upload-id, composite checksum and direct-part vectors are **N/A**. Assembled ≠ clean: a committed reference does not authorize quarantined bytes.

| Vector | Invariant | Test (`file::test`) | Status |
| --- | --- | --- | --- |
| Byte limit (chunk) | chunk over `max_chunk_bytes` rejected before staging | `drive/tests/Uploads/R07StagingSnifferContractsTest.php::rejects a stat-able chunk one byte over max_chunk_bytes before staging` | fast |
| Byte limit (total) | PATCH cannot grow past `max_bytes` | `drive/tests/Uploads/R07StagingSnifferContractsTest.php::rejects a PATCH that would grow past max_bytes after a partial upload` | fast |
| Declared size mismatch | parts sum < total size → no blob | `drive/tests/Uploads/UploadIntegrityTest.php::S3 complete при сумме частей меньше total_size_bytes → отказ, блоб не создан, F-P1.4-03` | fast |
| MIME / signature spoof | concrete declared MIME contradicting magic bytes rejected | `drive/tests/Uploads/UploadIntegrityTest.php::commit отклоняет объект с mime image/png и magic-bytes ZIP, F-P1.4-11`; `drive/tests/Uploads/R07StagingSnifferContractsTest.php::MagicByteSniffer rejects PNG declared when the head is a ZIP archive` | fast |
| Repeated part / retransmit | offsets must match; idempotent retransmit only at server offset | `drive/tests/Uploads/R07StagingSnifferContractsTest.php::rangeMatches requires staged length to cover offset plus chunk length exactly`; `…::accepts an idempotent retransmit when the upload is already complete at the server offset` | fast |
| Staged truncation / terminal resurrection | session lock; missing staged object refuses, terminal session never revives | `drive/tests/Uploads/UploadSessionCriticalSectionTest.php::append() при offset > 0 и пропавшем staged-объекте ОТКАЗЫВАЕТ, а не усекает файл, R-03`; `…::коммит реклеймленной сессии отбивается, узел не создаётся, статус не воскресает, R-11`; `…::putPart() перечитывает статус под локом: часть терминальной сессии не пишется, RV-02` | fast (races: server) |
| Double commit | replay returns same revision, no re-ingest | `drive/tests/Uploads/P3/CompleteUploadIdempotencyTest.php::SEC-R19-replay replays persisted exact revision after a newer head and without staging`; `…::SEC-CLEANUP-input-fingerprint rejects changed completion intent and corrupt authoritative pointers without ingest` | fast |
| Metadata rollback | staged bytes survive outer rollback for retry | `drive/tests/Uploads/P3/CompleteUploadIdempotencyTest.php::SEC-R13 keeps protocol bytes until outer commit and retains them after outer rollback`; `media/tests/Cas/BlobCommitAtomicityTest.php::commitStaged keeps the temp file reusable across an outer SQL rollback` | fast |
| Read before verdict (assembled ≠ clean) | pending/infected head never opens bytes | `drive/tests/Uploads/P3/ContentDeliveryBoundaryTest.php::SEC-R17 checks pending head, explicit older clean, metadata and sticky infection before opening bytes` | fast |
| Scanner verdict error / legacy bypass | required failure never grants bytes; legacy `not_required` cannot authorize | `drive/tests/Uploads/P3/QuarantineBoundaryTest.php::SEC-R07 required structured failure never grants PHP or share bytes`; `…::SEC-R01 legacy not_required and bare clean cannot authorize required public bytes` | fast |
| Strict / direct revoke | strict refuses bearer URLs; direct URL bounded by TTL | `drive/tests/Uploads/P3/ContentDeliveryBoundaryTest.php::SEC-R16 strict refuses bearer URLs before touching a signer`; `…::SEC-R16 explicit direct delivery binds exact immutable bytes and TTL with bounded revoke` | fast |
| Cross-tenant dedup | same bytes in two tenants → two blobs | `media/tests/Cas/BlobDedupScopeBehaviourTest.php::tenant: одинаковые байты в разных тенантах → ДВА блоба, два объекта` | fast |
| Orphan staged object | orphan detected, owned object kept | `media/tests/Cas/BlobOrphanSweepTest.php::обнаруживает настоящий orphan и пропускает закоммиченный объект (report-only)` | fast |
| Checksum mismatch (client SHA-256) | server rehashes; lying client hash / ETag rejected | `drive/tests/Uploads/TusFlowTest.php::лживый client sha256 отклоняется до записи версии`; `drive/tests/Uploads/S3MultipartTest.php::лживый ETag не используется как адрес — сервер пересчитывает sha256` | fast |
| Filename `../` / control / RTL | the name is metadata: the storage key is the content-addressed CAS key (opaque, never derived from the name); the resumable request rejects path separators; download disposition strips CR/LF, control chars and separators; no separate control/RTL rejection policy (metadata only) | `drive/tests/Feature/R07RequestRuleContractsTest.php::R07Request surfaces the resumable name separator custom message`; `drive/tests/Http/ContentResponderTest.php::strips CR/LF, control characters and path separators from the disposition` | fast |
| Finite completion deadline (D19) | one monotonic budget `uploads.resumable.complete_timeout_seconds` (1..300, default 60) started in `UploadOperations::complete` before `withSession(attempts: 3)`, shared by retries; checked before the staged read, between 64 KiB chunks and before the receipt; refusal publishes nothing and keeps the Assembled staged bytes | `drive/tests/Uploads/P3/CompletionDeadlineTest.php::D19-config types the completion budget as 1..300 seconds with a 60 second default`; `::D19-slow-stream refuses a staged stream that outlives the budget between chunks without publishing`; `::D19-retry shares one monotonic budget across attempts instead of resetting it`; `::D19-request starts the budget once before the session critical section of UploadOperations::complete`; `::D19-interrupted rejects a staged stream that stops making progress` | fast (fake clock) |
| Blocking staged storage I/O (D19) | a socket staged stream gets `stream_set_timeout` ≤ remaining budget before each read; local files are local I/O; any other stream (user-space wrapper, e.g. a remote SDK wrapper) is refused as an unsupported capability | `drive/tests/Uploads/P3/CompletionDeadlineTest.php::D19-transport bounds a socket stream with the remaining budget and reads it`; `::D19-transport refuses a stream without an enforceable timeout as an unsupported capability` | fast; real storage latency → P8.2 (server/stand) |
| Completion storage calls (D19) | before the S3-proxy assemble / completeness probe in `UploadOperations::complete` and before the staged open, CAS prepare and receipt in `CompleteUploadAction`, the session disk (also the CAS disk) is preflighted: only a local Flysystem adapter qualifies; a remote adapter (no per-call timeout in Flysystem) is refused as an unsupported capability before any storage call | `drive/tests/Uploads/P3/CompletionDeadlineTest.php::D19-preflight refuses an unboundable remote disk before any staged open, CAS write or assemble` | fast (strict spy adapter); remote-disk completion unsupported until a bounded driver exists |
| Native AWS multipart | — | N/A (proxy handler) | N/A |

## Out of scope

Host authentication, transport security of the host application, and the security of the configured
storage disk and scanner.
