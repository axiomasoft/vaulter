Six correctness gaps. The named hard-risk resolutions (group use, aliases, relative names, attributes, union/nullable/intersection/DNF, catch, trait `use` / `insteadof`) resolve to the right FQCN. `parent` / `self` / `static` stay unresolved, which matches `NameResolver` and the harness. New/stale key and count, required `reason`, `never` only for the three permanent I4 categories, and block-mode empty lists match the design.

`T_TMP=/tmp/vaulter-t-review scripts/t sqlite -p repo tests/Repo/Arch/InventoryHarnessTest.php` exited 1 before Pest: `ln: failed to create symbolic link '…/build/php84/bin/php': Permission denied`. The same file under `/usr/bin/php8.4 vendor/bin/pest --ci --testsuite=repo` passed 30 tests, 129 assertions. Those tests do not cover the cases below.

## 1. High — anonymous-class methods share one symbol

`IndexVisitor::enterNode` pushes the outer class (or null) for `new class`, then names the inner method `Outer::method` or `<file>`. `up` and `down` become one symbol, so later allowlist counts merge. An inner method whose name is not on the outer class is attributed to a symbol that does not exist.

All 25 core migrations are `return new class extends Migration`. `SourceIndex::forRepo` on `packages/core/database/migrations/2026_10_13_000002_create_v_nodes_table.php` reports every reference as `<file>`, including `table <file> => nodes` twice (once from `up`, once from `down`). The current tree has 0 references whose `Class::method` is not a declared method; the nested collision is latent (`packages/drive/src/Testing/Contracts/ScanLifecycleContractTests.php` has an anonymous class with no methods).

`SourceIndex::fromSources` of a file-level migration:

```text
[name]
  <file> => Vaulter\Schema\SchemaBuilder
  <file> => Vaulter\Schema\SchemaBuilder
[table]
  <file> => nodes
  <file> => nodes
```

Nested case (`Outer::bar` exists and does not mention `B`; `Outer` has no `other`):

```text
Vaulter\Drive\Outer::make => Vaulter\Media\A
Vaulter\Drive\Outer::bar  => Vaulter\Media\B
Vaulter\Drive\Outer::other => Vaulter\Media\C
```

The design symbol is the enclosing `Class::method`, `Class`, or `<file>` for code outside a class. These method bodies are inside a class, and the two migration methods are not the same symbol.

## 2. Medium — exact FQCN scope is a string prefix

`Rules::responsible()` uses `str_starts_with` for every non-`path:` element. A scope element `Vaulter\Access\Actor` also owns `Vaulter\Access\ActorExtra` and `Vaulter\Access\Actor\Child`. The design scope is an FQCN prefix, an exact FQCN, or a `path:` prefix; the longest match wins. No rule files exist yet. A correct P2.2 split that scopes one class exactly will be reported `out-of-scope`.

```text
winner type:Vaulter\Access\Actor file=exact owner=core
winner type:Vaulter\Access\ActorExtra file=wide owner=drive
winner type:Vaulter\Access\Actor\Child file=wide owner=drive
problem out-of-scope type Vaulter\Access\ActorExtra: rule wide:prefix:Vaulter\ is outside the scope of its file (the subject belongs to exact)
problem out-of-scope type Vaulter\Access\Actor\Child: rule wide:prefix:Vaulter\ is outside the scope of its file (the subject belongs to exact)
```

Prefix scopes that end in `\` (`Vaulter\Access\`) still match only that namespace. `fqcn` rules themselves stay exact.

## 3. Medium — `config` is any `get`/`has` and any `Config::*`

`isConfigCall` accepts a function `config`, any static call whose class ends in `Config`, and any method `get` or `has`. The design is `config(`, `Config::get(`, and `->get(` / `->has(` on a config repository, first argument, literal starting with `vaulter`. Only a positional argument is read, so `config(key: 'vaulter.named')` is dropped.

`SourceIndex::forRepo` (81 config refs) includes two request-attribute reads:

```text
CFG vaulter.request_id
    packages/core/src/Http/Problems/ProblemRenderer.php @ Vaulter\Http\Problems\ProblemRenderer::requestId
CFG vaulter_request_id
    packages/core/src/Support/RequestContext.php @ Vaulter\Support\RequestContext::requestIdOf
```

Those are `$request->attributes->get('vaulter.request_id')` and `->get('vaulter_request_id')`.

Synthetic `SourceIndex::fromSources`:

```text
[config]
  Vaulter\Drive\C::f => vaulter.x    # Config::set
  Vaulter\Drive\C::f => vaulter.y    # Config::get
  Vaulter\Drive\C::f => vaulter.z    # $cache->get
  Vaulter\Drive\C::f => vaulter.q    # $user->has
```

`config(key: 'vaulter.named')` is absent.

## 4. Medium — `table-literal` is any table-name string in Schema/Repositories

After the call/`$table` checks, any remaining string equal to a logical table (or `v_` plus that name) inside a class matching `(^|\\)(Schema|Repositories)\\` is `table-literal`. The design limits that kind to constants and arrays without call context.

`SourceIndex::forRepo` records 12 of these from `->model('<key>')` where the model key equals a table name:

```text
ActivityReadRepository::base => activity
ActivityStoreRepository::record => activity
ScanEvidenceRepository::{append,cleanCandidate,compatible,findOrFail,hasAttempt,infected,lockOrFail,recordReuse} => scan_evidence
OutboxStoreRepository::{keyName,newQuery} => outbox
```

`KeyColumnInspector::expected` arrays and `SchemaBuilder` entity constants/match arms are real table literals.

Synthetic class `Vaulter\Schema\Names`:

```text
[table-literal]
  Vaulter\Schema\Names => nodes          # const ['nodes'] — in spec
  Vaulter\Schema\Names::f => nodes       # $log = 'nodes'
  Vaulter\Schema\Names::f => nodes       # throw new RuntimeException('nodes')
```

Same matcher, synthetic only (no current call site passes a raw table string this way): `->on('nodes', …)` and `DB::select('nodes')` are kind `table`, because `on` is in `TABLE_CALLS` and every method on a class whose last segment is `DB` or `Schema` counts. The design lists `DB::table(` and `Schema::*`, plus `table(` / `from(` / `join(` / `->table(` / `$table`.

## 5. Medium — `route` includes any `->route(`

`isRouteCall` treats every method named `name` or `route` as a route call. The design lists `route(`, `->name(`, `Route::has(`, and `URL::route(`.

`SourceIndex::forRepo`:

```text
ROUTE token | packages/core/src/Http/Middleware/ResolveShareToken.php @ Vaulter\Http\Middleware\ResolveShareToken::handle
```

That is `$request->route('token')`. Synthetic `$this->route('also')` is recorded as route target `also`.

`->name(` is in the design. The five package names `vaulter`, `vaulter-documents`, `vaulter-drive`, `vaulter-filament`, and `vaulter-media` come from `Package::name(…)` on the service providers and follow that line.

## 6. Medium — `removed_in` is not bounded by the blocking phase

`Allowlist` accepts any of `P3|P4|P5|never`, and checks `category` only when `removed_in` is `never`. The design requires `removed_in` ≤ the invariant’s «Блокирует с» phase (I1/I2/I3 from P4, I4 from P3), except I4 categories `config-validation`, `host-lifecycle`, and `explicit-adapter` with `never`. I4 also requires a `category` from that set plus `activation` and `jwt-optional`.

```text
ACCEPTED I1 removed_in=P5 category=none
ACCEPTED I4 removed_in=P4 category=activation
ACCEPTED I4 removed_in=P3 category=none
ACCEPTED I2 removed_in=P5 category=none
REJECTED I4 removed_in=never category=activation
```

The `never` rejection matches the design. Exact new/stale comparison by `view|file|symbol|kind|target` and `count` matches the design.

FINDINGS
