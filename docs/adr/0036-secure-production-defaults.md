# ADR-0036 — Secure production defaults

Status: Accepted.

## Context

Production-fatal conditions were scattered across modules as ad hoc `environment('production')` throws
in configuration validators and registrars. They fired in every process, could not be accepted
deliberately, and left no uniform diagnostic.

## Decision

A module contributes narrow `Vaulter\Contracts\Security\ProductionInvariant` objects to the
`ProductionInvariant` slot under a stable `<module>.<name>` id (`id == key`). Core evaluates the
active modules' invariants in id order through `Vaulter\Security\ProductionInvariants`:

- HTTP: once at application boot; long-lived commands (`queue:work`, `queue:listen`, `horizon`,
  `octane:start`, `vaulter:relay`, `schedule:work`, `schedule:run`, and the outward side-effect commands
  `vaulter:relay:replay`, `vaulter:webhooks:redeliver`, `vaulter:webhooks:retry`) once on the canonical
  `CommandStarting` event, before the command handles. Build and doctor commands are exempt.
- Structural errors (malformed TTL, equal hub secrets, unknown driver, a malformed webhook allow or block CIDR) are not invariants: they fail in
  every environment before any risk classification, and their coercion and defaults are unchanged.
- A risk is accepted only with `vaulter.security.accepted_risks[<id>] = '<nonempty reason>'`; the
  doctor reports accepted, unaccepted, exempt and structural states. An empty reason accepts nothing.
- Failures are one aggregated `InsecureProductionConfiguration` without secret values.

Invariants shipped: `core.tenancy`, `drive.permission-driver`, `collaboration.secrets`,
`collaboration.hub-url`, `webhooks.schemes`, `webhooks.blocked-ranges`.

## Consequences

The former production throws in `DriveConfigValidator` and `CollaborationConfig::assertSecrets()` are
removed; the latter's non-production warning is replaced by the doctor report. Hub JWT key rotation (`kid` keyring) and
the webhook blocked/allowed ranges are implemented; a malformed range is rejected, never coerced.
