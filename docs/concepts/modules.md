---
title: Modules
description: How Vaulter packages declare themselves as modules — manifests, activation (auto and opt-in), the vaulter.modules switch, an invalid module graph, route caches and uninstall plans.
outline: deep
---

# Modules

Every Vaulter package is a **module**: its service provider only *offers* a manifest, and the
core's module kernel decides what is active, then registers the active modules' config,
container wiring, routes, commands, schedules and doctor checks (ADR-0027). An inactive
module registers nothing at runtime; its database schema is still installed.

## Activation

| Module | Package | Policy | Requires |
|---|---|---|---|
| `core` | `vaulter-core` | auto | — |
| `media` | `vaulter-media` | auto | `core` |
| `drive` | `vaulter-drive` (with its slices still located in `vaulter-core`) | auto | `core`, `media` |
| `webhooks` | `vaulter-core` (until it gets its own package) | **opt-in** | `core`, `drive` |

*Auto* modules are active unless switched off; *opt-in* modules — the ones that open outbound
network traffic or need secrets — only when switched on. One config key overrides the policy:

```php
// config/vaulter.php
'modules' => [
    'webhooks' => true,   // opt in to outbound webhooks
],
```

Values must be `true` or `false`, and keys must name installed modules. A module that is active
while a module it requires is not makes the graph invalid — a dependency is never switched on
silently. Switching the drive off (`'drive' => false`) removes all of it, its routes, commands and
bindings located in `vaulter-core` included; every module that requires it must be switched off too. Run `php artisan vaulter:modules` to see every module, its policy, whether it is active
and why. `vaulter.features.webhooks` no longer exists; a host config that still sets it fails at
boot with a pointer to `vaulter.modules.webhooks`.

## An invalid module graph

The graph is compiled once, after every provider registered and before any provider boots,
without touching the database or the network. When it is invalid (an unknown key, a non-boolean
value, a missing or inactive dependency, a conflicting declaration) **no** module is active:

- artisan still works; `vaulter:modules` and `vaulter:doctor` print the reasons and exit `1`;
  every other `vaulter:*` command is not defined;
- every route under the Vaulter prefix answers `503` with the problem code `module_graph_invalid`.

## Route and config caches

`php artisan route:cache` freezes the route set: a cached application never reloads routes from
the modules. Every module route group carries the `vaulter.module:<id>` middleware — a cached
route of a module switched off afterwards answers `404` — and the module fingerprint of the boot
that cached it. `vaulter:doctor` (`core.modules.cache`) reports a cache built under another
activation, or an active module whose routes are missing from it. After changing `vaulter.modules`,
rebuild the caches (`php artisan optimize:clear && php artisan optimize`) and restart the workers.

## Uninstalling a module

Disabling a module keeps its tables and data. `php artisan vaulter:module:uninstall <module>`
plans the removal of a module's schema as a dry run — its migrations and tables, the modules that
require it, foreign keys other owners hold on its tables — and says why it would be refused. Until
the create-migrations are split by owner, `--force` and `--purge` refuse.

## See also

- [Architecture](/concepts/architecture)
- [Configuration](/guides/configuration)
- [Artisan commands](/reference/commands)
