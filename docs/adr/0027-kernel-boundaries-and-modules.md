# ADR-0027 — Kernel boundaries and the module system

**Status:** accepted — supersedes [ADR-0019](/adr/0019-kernel-ownership)

## Context

[ADR-0019](/adr/0019-kernel-ownership) made `vaulter-core` the owner of the whole schema, the shared
models, access, sharing, audit, events and profiles. Core grew to hold every concept used by more than
one package, and packages switched behaviour on what happened to be installed:
`app()->bound(DriveHandleFactory::class)`, `class_exists(...)` and `bound(NodeDocumentHeads::class)`
decide correctness at runtime, sibling providers register routes, bindings and tags from their own
`boot()`, and nothing checks that two packages do not claim the same name. ADR-0019's honest Composer
graph (documents and filament require drive) and the AzGuard move stay valid; its ownership rule does
not.

## Decision

**1. The kernel criterion (D5).** Something belongs to core only if (a) the kernel itself calls it while
running any operation, or (b) modules cannot work together consistently without exactly one
implementation (one transaction, one outbox, one registry). "Several modules use it" is not a criterion:
a consumer takes the contract from the package it depends on. Core keeps the module system, the
operation context and pipeline, the transaction, events with outbox and relay, idempotency, errors,
identity and tenancy, the snapshot base, the HTTP/config/doctor frameworks, telemetry, attributes and the
testing kit. Domain models, tables and services move to their owners.

**2. Module states.** *installed* (Composer code) → *discovered* (a provider offered the manifest) →
*active* (activation policy plus override) → *ready* (its schema and infrastructure answer, checked
lazily before its first operation) → *executing*. The schema of every installed module is migrated
whether it is active or not; disabling never deletes data.

**3. Four mechanisms, no second framework.**

- **Manifest** — `Vaulter\Modules\VaulterModule`: `id()`, `version()` (the SPI protocol `MAJOR.MINOR`),
  `activation()`, `requires()` (`id => ^MAJOR.MINOR`), `register(ModuleBuilder)`. The builder only
  declares: config root, schema path, routes, translations, commands, listeners, schedules, route
  middleware, capabilities, slots and contributions, event and problem sources. Values are scalars,
  enums and class-strings; no closures, no I/O, no container lookups, no actor, tenant or clock.
- **Compilation and seal** — a package provider calls `offerModule()` from `register()` and registers
  nothing itself. The core provider compiles every offered manifest in an application *booting*
  callback (after all `register()`, before any `boot()`), with a pure compiler, and seals the result in
  `Vaulter\Kernel`. Ids, protocols, overrides, the requires graph of the active modules, capability
  cardinalities and slots are validated together; any defect yields an invalid graph with every reason
  and nothing active (`module_graph_invalid`, 503). Any declaration or offer after the seal throws
  `registry_frozen`. A fingerprint over the active set travels with caches.
- **Capabilities** — a typed registry of single or many implementations of a contract
  (`RequiredSingle`, `OptionalSingle`, `Many`), provided by a module or chosen by the host in config.
  Consumers ask `Capabilities::has()/require()/all()`; nobody probes the container.
- **Slots** — named, typed extension points with a kind (`Guard`, `Restriction`, `Contributor`,
  `Handler`, `EventSink`, `OutboxConsumer`, `OperationMiddleware`) owned by the module that owns the
  concept, ordered by `(priority, id)`, unique by id and handler key. A slot ships only with a built-in
  consumer, a built-in contribution and a contract test (D8).

**4. Activation (D22).** `Auto` for product modules without outside effects (media, drive, documents,
adapters with their own config choice); `OptIn` for modules that open the network or need secrets
(webhooks, collaboration). `vaulter.modules.<id>` overrides with a strict boolean; an unknown key, a
non-boolean value or an active module with an inactive dependency is a compilation error — the
dependency is never enabled silently.

**5. Slices that still live in core (D39).** Until the packages are split, the manifest of the package
that owns a concept declares its core-located slices (drive declares the access, sharing, activity and
changes routes and services that sit in core); the core provider offers exactly two manifests: `core`
and the logical `webhooks`.

## Consequences

- The kernel compiles and seals the graph from this release; the real manifests, the application of
  their declarations to routes, commands and listeners, and the removal of the registrars follow in the
  same phase, then every `bound()`/`class_exists()` activation switch is replaced by a capability or a
  slot, and the boundary scan of implicit activation becomes blocking.
- Disabling a module removes its routes, listeners, bindings and commands (diagnostics excepted); the
  route cache answers a disabled module's routes with 404 through `vaulter.module:<id>`.
- File moves between packages (tables, models, migrations, FQCNs) happen in the following phase by the
  type map; until then contracts of drive-owned concepts may live in core namespaces.

## Alternatives rejected

- **Keep ADR-0019** — core keeps growing with every shared concept, and "installed" stays a correctness
  switch.
- **A second container or router for modules** — Laravel's container, router and scheduler already
  do the work; the kernel only decides what is declared and in which order.
- **Container tags and Laravel events as the extension mechanism** — unordered, unchecked, open after
  boot, and silent when a contribution is missing or doubled.
- **A core-provided slice manifest per owner merged with the package's own manifest** — two sources of
  one module id need a merge mechanism and make a disabled owner's slices unprovable (D39).

## References

- [ADR-0019 — Kernel ownership](/adr/0019-kernel-ownership) (superseded)
- [ADR-0023 — Event pipeline](/adr/0023-event-pipeline)
- [ADR-0024 — REST v1 & errors](/adr/0024-rest-v1-and-errors)
