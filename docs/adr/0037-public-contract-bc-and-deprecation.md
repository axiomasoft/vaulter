# ADR-0037 — Public contract, BC gate and deprecation policy

Status: Accepted.

## Context

The public contract was described in prose and partly by snapshots. P6 needs one catalog of surfaces with their machine source, 1.x promise, detection and qualification (D14), without claiming protection where no snapshot exists.

## Decision

- `docs/reference/compatibility.md` (and the ru page) is the single catalog; each row names source, promise, detection and qualification, or marks the row manual / not_checked (D17).
- Named arguments are API for `#[Api]` types; unstable constructors are `#[Internal]` (D27).
- Deprecation: at least one minor cycle, one warning per process outside the hot path, replacement and removal version stated, config alias plus doctor message; removal only in 2.0 except security exceptions with an advisory (D18).
- Root-only `composer.json` keys are not package promises (D8).

## Consequences

The catalog is preliminary until P6.3 closes I13 and P6.2 adds the BC gate. Behavior rows (events, exit codes, queue/storage semantics, Vue runtime) stay owner-qualified.
