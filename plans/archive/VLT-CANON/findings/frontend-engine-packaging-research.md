# VLT-CANON research — frontend-engine-packaging

**Дата:** 2026-07-11 · **Метод:** research-fanout (sonnet/perplexity-web deep+advanced) + adversarial verify (независимый повторный поиск). RAG:✅ 2026-07-11.
Вердикты verify встроены к load-bearing утверждениям. REFUTED — детальные поправки, зафиксированы в §Verify.

## Findings

### F1 **[load-bearing]**

Across Filament (v4+), Nova (5), and Statamic (v5) the dominant, docs-endorsed layout is Model 2: frontend JS/Vue/Alpine lives in resources/js (+ resources/css) INSIDE the PHP/Composer package, is built with Vite (or a custom bundler) into resources/dist or an equivalent build output, and is distributed solely via Composer — no separate npm package is published for the plugin's own assets. Statamic's own addon docs explicitly say 'production assets are compiled into resources/dist first' then copied to public/vendor/<addon> via vendor:publish (or a dev symlink). Filament's docs show the same shape via Vite::asset() feeding FilamentAsset::register().

- Источник: [Statamic — Vite Tooling for Addons](https://statamic.dev/addons/vite-tooling)
- Применимость к vaulter: Vaulter's engine-specific frontend (TipTap now, others later) should default to living inside the relevant PHP package's resources/js, Vite-built, shipped/versioned as part of that Composer package — matching how DocumentHandler-owning packages already ship PHP. Avoid over-engineering a separate npm-per-engine split (vaulter's proposed Model 3) unless a concrete non-Laravel reuse case appears.

### F2 **[load-bearing]**

Filament v4 asset registration API: FilamentAsset::register([...], package: 'vendor/package-name') in a service provider's boot(); assets are Js::make('id', path), Css::make('id', path), or AlpineComponent::make('id', path). For bundled JS, the documented pattern is to build with Vite, add an entry in vite.config.js, run the build, then register the compiled file as a URL via Js::make('timezone', Vite::asset('resources/js/timezone.js')) — Vite::asset() returns a URL so `php artisan filament:assets` does NOT copy it (that command only copies file-path-based assets). Blade resolves the runtime script URL via FilamentAsset::getAlpineComponentSrc('id', package: ...) / getScriptSrc('id') using the SAME string id chosen at registration.

- Источник: [Filament 4.x docs — Advanced: Assets](https://filamentphp.com/docs/4.x/advanced/assets)
- Применимость к vaulter: This is the closest ecosystem-idiomatic analog to vaulter's componentMap-by-handler.id: a short string id chosen at registration time is the sole lookup key at render time. Vaulter should formalize its componentMap into an explicit registerEngine(id, component)-style public API (mirroring AlpineComponent::make/getAlpineComponentSrc) rather than leave it as an internal switch, so third-party engine plugins can register themselves the same way Filament plugins register assets.

### F3

Laravel Nova 5 (current major, 2025) did NOT move its own internal asset pipeline to Vite — Nova's core JS is still Mix/webpack-based; the Nova team stated the Vite migration 'didn't make it into the current major release (v5)'. Laravel's own docs now describe Laravel Mix as 'a legacy package that is no longer actively maintained', recommending Vite. Third-party Nova cards/tools, however, CAN be built independently with Vite in library mode (UMD output, externalizing vue/Nova/laravel-nova) and registered via Nova::script('id', path) / Nova::style('id', path) inside Nova::serving(). The actual component binding happens in JS: Nova.booting((app) => app.component('acme-analytics-card', VueComponent)) — the Vue component NAME is the runtime contract; the PHP Card class only implicitly relies on that name matching, with no formal cross-checked registry.

- Источник: [laravel/nova-issues discussion #4502 — building Nova components with Vite](https://github.com/laravel/nova-issues/discussions/4502)
- Применимость к vaulter: Confirms name-based (string-key) binding is the norm even without compile-time safety in the flagship reference implementation (Nova). Useful precedent that vaulter's id-based componentMap doesn't need a heavier registry mechanism than Nova has — but also a caution: Nova's lack of a compile-time id<->component check is a known pain point worth avoiding by adding a dev-mode runtime warning when a DocumentHandler.id has no registered Vue component (open question below).

### F4 **[load-bearing]**

Statamic v5 addon JS registers CP Vue components into a global registry via Statamic.booting(() => { Statamic.component('vendor-addon-field', VueComponent) }); the addon's PHP fieldtype/widget definition then references that same string name. The addon's ServiceProvider declares protected $vite = ['input' => [...], 'publicDirectory' => 'resources/dist']; assets are built inside the addon repo and published/symlinked into public/vendor/<addon> in the host app — again single-repo, Composer-only distribution, no separate npm publish of the addon's own runtime.

- Источник: [Statamic v6 docs — Building an Addon](https://v6.statamic.dev/addons/building-an-addon)
- Применимость к vaulter: Third confirmation (with Filament, Nova) of the same shape: PHP-side declares a string name/id; JS-side registers a component under that exact string into a shared host runtime. Vaulter's handler.id -> componentMap is architecturally identical to this triad's standard pattern, not a workaround — validates keeping it, just needs a public registration function instead of a private map.

### F5 **[load-bearing]**

A real-world precedent nearly identical to vaulter's exact situation exists: awcodes/filament-tiptap-editor packages TipTap 2 plus ~15 individual TipTap extensions (bold, table, mention, code-block-lowlight, etc.), Alpine.js/Async-Alpine glue, and supporting libs (highlight.js, lowlight, tippy.js) entirely INSIDE one Filament plugin repo. It does not use Vite or Mix; it has a custom bin/build.js bundler, with dev:styles/build:styles (Tailwind) and dev:scripts/build:scripts (JS) as separate deterministic steps, outputting to resources/dist/filament-tiptap-editor.css/js. package.json has no separate npm-facing version (implied private usage pattern); composer.json is the only consumer-facing artifact, and the ServiceProvider is where FilamentAsset registration of the prebuilt dist files happens.

- Источник: [awcodes/filament-tiptap-editor (GitHub)](https://github.com/awcodes/filament-tiptap-editor)
- Применимость к vaulter: Directly on-point precedent for vaulter's TipTap-as-plugin goal within a Filament-adjacent ecosystem: bundle TipTap + its extensions fully inside the owning PHP package's frontend build, ship compiled dist via Composer, do not publish a separate npm package for it. Supports collapsing vaulter's default TipTap engine into packages/documents (or packages/filament) resources/js rather than exposing it as a standalone @vaulter/vue-engine-tiptap npm package, unless reuse outside vaulter's own Filament UI is a real near-term requirement.

### F6 **[load-bearing]**

None of Filament, Nova, or Statamic publish a separately npm-versioned artifact for a given plugin's/addon's/card's own JS by default — frontend asset changes ship inside the same Composer package tag as the backend PHP; there is no documented practice of pinning e.g. '@vendor/plugin-ui@0.9.4' against 'vendor/plugin@^1.3' for first-party or typical third-party plugins in this ecosystem. Where PHP/npm split does appear (e.g. filament-curator vs filament-tiptap-editor's package.json), package.json is functionally private/build-tooling-only, not a distribution channel.

- Источник: [awcodes/filament-curator composer.json (GitHub)](https://github.com/awcodes/filament-curator/blob/3.x/composer.json)
- Применимость к vaulter: Supports a single-version-train decision for vaulter's default engine: don't split TipTap-engine frontend into its own SemVer track distinct from the owning PHP package/DocumentHandler contract. If/when a genuinely separate reusable JS engine package is warranted (see Univer/TipTap-core findings below), that should be a deliberate, explicit exception, not the default.

### F7

TipTap v3 (2025) itself is packaged as a layered npm monorepo, not a single package: @tiptap/core (headless kernel) + @tiptap/pm (a version-pinned wrapper re-exporting the needed ProseMirror submodules, to avoid consumers manually matching many prosemirror-* versions) + dozens of granular @tiptap/extension-* packages + framework adapters (@tiptap/react, @tiptap/vue-2, @tiptap/vue-3) + curated convenience bundles (starter-kit, and newly in v3, kits like TableKit that group related extensions to reduce dependency clutter).

- Источник: [ueberdosis/tiptap GitHub discussion #5793 — v3 packaging changes](https://github.com/ueberdosis/tiptap/discussions/5793)
- Применимость к vaulter: This is TipTap's OWN internal npm packaging (pure npm, framework-agnostic), separate from how a Laravel package embeds it. It's a good model for 'core + engines' naming IF vaulter ever exposes @vaulter/vue's engines as standalone reusable npm packages, but the awcodes-editor precedent shows this level of granularity is NOT required just to ship TipTap-in-Filament — vaulter can bundle TipTap's npm packages internally without re-exposing that granularity to its own consumers.

### F8

General 2025 npm-monorepo naming convention (not specific to Laravel/Filament/Nova/Statamic): treat the npm @scope as an org/product namespace only, and encode role/architecture in the package NAME, not in nested pseudo-scopes — e.g. @org/core, @org/engine or @org/engine-x for multiple engines, @org/engine-x-plugin-foo for engine-scoped plugins, or @org/plugin-foo for engine-agnostic plugins. Avoid inventing '@org/engine-x/*'-style fake sub-scopes.

- Источник: [npm CLI docs — npm scope](https://docs.npmjs.com/cli/v11/using-npm/scope/)
- Применимость к vaulter: If vaulter's pre-1.0 rename decides to split @vaulter/vue into @vaulter/vue (core canon/shell) + @vaulter/vue-engine-tiptap (or similar), this convention argues for exactly that flat naming (engine identity in the package NAME, single @vaulter scope) rather than deeper nesting — but per the Filament/Nova/Statamic/awcodes precedents above, this split is only justified once there's a real reuse driver, not by default.

### F9

Univer (2025 spreadsheet-editor engine, comparator for 'engines as plugins') has a genuinely decoupled plugin architecture at the pure-npm level: composition root `new Univer(config)`, explicit `univer.registerPlugin(SomePlugin, options)` calls per capability/engine in a required order, and an optional higher-level Facade API published as its own npm package (@univerjs/facade, first published on npm January 2025) that individual feature plugins attach onto via separate `.../facade` submodule imports (e.g. '@univerjs/sheets/facade'). Preset mode wraps this for convenience; plugin mode requires manual facade imports per feature.

- Источник: [Univer docs — Node via plugin (playground)](https://docs.univer.ai/playground/sheets/node-via-plugin)
- Применимость к vaulter: Univer is the clearest 'core engine npm package + registerPlugin(EnginePlugin)' precedent found — but it is a pure-JS product with no PHP backend counterpart to keep in sync, unlike vaulter. It is the right reference IF vaulter later wants a genuinely standalone, framework-host-agnostic @vaulter/vue-core with registerEngine()-style plugin registration decoupled from any specific Laravel package; it is over-engineering for the current pre-1.0 single-Laravel-app-consumer reality that Filament/Nova/Statamic plugins actually operate in.

### F10 **[load-bearing]**

Nextcloud's Viewer app moved its component-registration API away from an implicit global (`OCA.Viewer.registerHandler(...)`, loaded via \OCP\Util::addScript) toward an explicit ES-module import (`import { registerHandler } from '@nextcloud/viewer'`, loaded via \OCP\Util::addInitScript) specifically because the global-object pattern was fragile to script LOAD ORDER; the project's own README now marks the OCA.Viewer.* global path as 'not recommended'. Registration in both forms matches by MIME type (`mimes: [...]`) plus a string `id` and a `component`.

- Источник: [nextcloud/viewer README (GitHub)](https://github.com/nextcloud/viewer)
- Применимость к vaulter: Direct, load-bearing caution for vaulter if/when its default-engine componentMap becomes a real cross-package registry consumed by third-party engine plugins: use an explicit exported registration FUNCTION (e.g. `import { registerDocumentEngine } from '@vaulter/vue'`) rather than relying on plugin scripts mutating an implicit global/window object in an unspecified load order — Nextcloud's own ecosystem hit this exact failure mode and moved off it.

### F11 **[load-bearing]**

WOPI (Web Application Open Platform Interface, current MS-WOPI spec + ONLYOFFICE/Collabora implementations) uses a fundamentally different, DECLARATIVE mechanism for host-side editor capability discovery than the Filament/Nova/Statamic JS component registries: a static `discovery.xml` served at /hosting/discovery, containing `<app name=... mimetype-or-friendly-name>` elements each wrapping `<action name='view'|'edit'|... ext=... requires='locks,update,...' urlsrc=...>` elements. The host (storage/server side) parses this XML to learn which editor engines exist, what file types/extensions/actions each supports, and what iframe URL template to use — there is no in-process JS registry involved at all; it's cross-process/cross-service capability advertisement.

- Источник: [[MS-WOPI]: WOPI Discovery — Microsoft Open Specifications](https://learn.microsoft.com/en-us/openspecs/office_protocols/ms-wopi/426e8b44-2833-416b-a135-b5d68df3ba2d)
- Применимость к vaulter: WOPI's discovery.xml (declarative capability manifest, PHP/server-side concern) is architecturally the correct analog to vaulter's EXISTING DocumentHandler registry (mime/ext dispatch, capabilities, priority, fallback) — NOT to the frontend componentMap. This cross-check reinforces that vaulter's two mechanisms (PHP DocumentHandler capability dispatch vs frontend id-keyed component registry) are properly separate concerns already, matching how even a fully editor-agnostic protocol like WOPI keeps capability-discovery (XML) and actual embedding UI (external URL/iframe, not a component registry) as distinct layers — supports NOT merging DocumentHandler and componentMap into one structure during the canon refactor.

## Recommendations (агента-исследователя)

- Default new/refactored engine packaging to Model 2 (resources/js inside the owning PHP package, Vite-built, distributed solely via Composer, single version train with the PHP package) — this is what Filament, Nova, Statamic, and the directly-analogous awcodes/filament-tiptap-editor all do; do not create a separate npm-published package per engine unless a concrete non-Laravel/cross-host reuse need is identified.
- Extract the current implicit componentMap in VaulterDocumentNode.vue into an explicit, exported registration function (e.g. registerDocumentEngine(handlerId, component)) in @vaulter/vue's public API, called by each engine plugin at load time — mirrors Filament's AlpineComponent::make/getAlpineComponentSrc, Nova's Nova.booting/app.component, and Statamic's Statamic.booting/Statamic.component idiom, and avoids the load-order fragility Nextcloud's Viewer explicitly moved away from (no implicit global/window mutation).
- Keep the DocumentHandler PHP registry (mime/ext, capabilities, priority, fallback) as a structurally separate layer from the frontend id-keyed component registry — do not fold them into one mechanism during the canon/plugin refactor; this mirrors how even WOPI keeps declarative capability-discovery separate from the actual embed/render mechanism.
- Since vaulter is pre-1.0, use the SemVer-renaming freedom now to settle on flat @vaulter/<role> naming (e.g. keep @vaulter/vue as the shell/core, and only introduce @vaulter/vue-engine-tiptap-style packages if/when an engine genuinely needs independent npm distribution) rather than nested pseudo-scopes, per current npm-scope conventions.
- Bind any future engine-frontend breaking changes to the same Composer/npm major-version bump as the owning PHP package's public contract (DocumentHandler.id, capabilities) rather than inventing an independent SemVer track for engine JS — none of the three reference ecosystems do independent JS versioning for plugin assets by default.
- If/when a fully host-agnostic, standalone @vaulter/vue-core with pluggable engines becomes a real goal (beyond the current Filament-adjacent single-app consumer), use Univer's registerPlugin(EnginePlugin, options) + optional Facade-package pattern as the reference shape, not TipTap's own internal extension granularity (which is an implementation detail vaulter can bundle, not re-expose).

## Open questions

- No source in this research covers how to keep a backend capability-registry id (vaulter's DocumentHandler.id) and a frontend component-registry id in guaranteed sync (compile-time check, build-time codegen, or runtime dev-mode warning) — Filament/Nova/Statamic all rely on informal string-matching discipline with no cross-checked registry; whether vaulter should add tooling here is unresolved by these sources.
- No concrete real-world example was found of Model 3 (a rich text/document editor ENGINE specifically, as opposed to a generic admin-UI library) shipped as a standalone npm package consumed by an unrelated non-Laravel host alongside a Laravel/Filament integration — the Univer and TipTap findings are the closest analogs but are not document-storage-node-tree editors integrated into a Laravel package ecosystem the way vaulter's DocumentHandler model is; this remains a genuine gap if vaulter wants direct precedent rather than an adapted analogy.
- Filament v4's plugin-authoring guide for third-party FIELD TYPES (as opposed to generic asset registration) was not independently fetched in full; the AlpineComponent::make + x-load-src pattern was confirmed but the complete recommended package skeleton (directory layout, testing, publish tags) for a field-type plugin specifically was not verified beyond the assets doc page.

## Verify (adversarial, независимый поиск)

### CONFIRMED — 1. Model 2 (frontend in resources/js inside PHP package, Composer-only distribution, no separate npm) is the dominant docs-endorsed layout a…
Statamic vite-tooling docs independently confirm: source resources/js+css -> build to resources/dist -> vendor:publish to public/vendor/<addon> (statamic.dev/addons/vite-tooling). Filament docs confirm Vite::asset() feeding FilamentAsset::register(). Nova 5 card docs confirm resources/js+css inside a Composer package, Composer-only distribution, but Nova's documented build tool is Mix (webpack.mix.js), not Vite/custom-bundler by default — the claim's parenthetical 'or a custom bundler' stretches to cover this but Nova is a weaker fit than stated for the 'Vite' framing.
- Контр-источник: https://nova.laravel.com/docs/v5/installation

### CONFIRMED — 2. Filament v4 FilamentAsset::register([...], package: ...) with Js::make/Css::make/AlpineComponent::make; Vite::asset() returns a URL so fi…
Independent search confirms the path-vs-URL distinction: filament:assets copies filesystem-path assets, and Vite::asset() results are treated as URLs and thus not copied, matching the exact Js::make('timezone', Vite::asset(...)) pattern cited in current Filament docs.

### REFUTED — 3. Statamic v5 addon JS registers CP Vue components via Statamic.booting(() => { Statamic.component('vendor-addon-field', VueComponent) }); …
The $vite/publicDirectory=resources/dist detail and overall single-repo Composer-only distribution are confirmed. However the specific registration API cited is wrong: current Statamic docs use Statamic.$components.register('handle-fieldtype', Component) inside Statamic.booting(), not a bare 'Statamic.component(...)' call — Perplexity explicitly flagged 'Statamic.component(...)' as likely outdated/misremembered shorthand for the documented API.
- Контр-источник: https://statamic.dev/fieldtypes/build-a-fieldtype

### CONFIRMED — 4. awcodes/filament-tiptap-editor packages TipTap 2 + ~15 extensions + Alpine/Async-Alpine + highlight.js/lowlight/tippy.js in one repo; no …
Independently confirmed: build:scripts/dev:scripts run node bin/build.js (custom bundler, no Vite/Mix), styles built separately via Tailwind CLI to resources/dist/filament-tiptap-editor.css, and package.json is marked "private": true (blocks npm publish) while still carrying a version field — matching the claim's 'no separate npm-facing version / implied private usage' characterization.

### CONFIRMED — 5. None of Filament/Nova/Statamic publish a separately npm-versioned artifact for a plugin's own JS by default; asset changes ship inside th…
filament-curator's package.json independently confirmed as "private": true, build-tooling-only (Tailwind/esbuild), not published to npm; composer.json is the real distribution artifact. Nova 5 card docs and Filament asset docs corroborate composer-only distribution as the norm; no evidence found of a documented dual-pinning convention as described.

### CONFIRMED — 6. Nextcloud Viewer moved from implicit global OCA.Viewer.registerHandler(...) (via Util::addScript) to explicit ES-module import registerHa…
Independent search confirms: Viewer README explicitly labels OCA.Viewer registration as legacy/'not recommended' citing script-load-order fragility, recommends @nextcloud/viewer's registerHandler() loaded via Util::addInitScript(), and both APIs match handlers by id/mimes/component.
