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

## Out of scope

Host authentication, transport security of the host application, and the security of the configured
storage disk and scanner.
