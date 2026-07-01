# Vaulter — план разработки платформы виртуального хранилища (монорепо)

> Статус: рабочий план для пошаговой реализации (chatom-уровень детализации). Postgres-first, MySQL-портируемо. Заземлён на: текущий код `v_*` Vaulter, эталонный пакет **chatom** (механика панелей/схемы/id-стратегий), и поколоночное исследование Nextcloud/ownCloud, Google Drive, Seafile/Git/Dropbox/S3.
>
> Зонтичный бренд: **Vaulter**. Диск: **Vaulter Drive**. Движок документов: **DocDrive**. Файловый слой: **Vaulter Media** (standalone). После реализации этот файл мигрирует в `docs/` (VitePress), а `docs/plan/` удаляется — см. §8.

---

# 🔖 СТАТУС РЕАЛИЗАЦИИ И ОСТАВШИЕСЯ РАБОТЫ (читать первым)

> Эта секция — единственный источник правды по прогрессу. Ниже разделы §1–§12 + «Финализация дизайна / H.1–H.7» — это исходный design-doc (всё ещё актуален как спецификация). Ветка: **`refactor/vaulter`**, PR **#20** (`refactor/vaulter` → `main`). Базовый `main` был ещё DocVault-namespaced; в ветку влит `origin/main` (#17/#19 уже присутствовали как `bc6ee1a`/`921805f`). Если PR смержен — новые работы вести от обновлённого `main` новыми ветками.

## ✅ Сделано (24 коммита, все гейты зелёные: core 551 · filament 52 · vue 79 · hocuspocus 13; PHPStan max 0; Pint ✓; types 100%; docs:build ✓)

| Область | Коммит | Что есть |
|---|---|---|
| Hardened-план | `613b741` | этот документ (+ H.1–H.7 свод) |
| CI rename-фикс | `81d9245` | @docvault→@vaulter в workflows |
| id-стратегии | `852ce25` | ulid/uuid7/bigint + VaulterSchema/HasVaulterKeys |
| Storage Panels | `84f36b5` | config-first, zero-migration, make-panel, PanelRegistry |
| Drive schema/models/generator | `ef62ec5` | PanelTablesBuilder, v_storages/v_nodes/v_files/v_blobs/…, adjacency Node, panel:migrations |
| **Race-free CAS** | `5797feb` | BlobStore, sha256-дедуп, two-phase GC + resurrection, Flysystem |
| Drive домен | `273ce73` | `Vaulter::for()`, действия folder/upload/move/copy/rename/trash/restore, квота |
| Drive REST | `fe92ce3` | контроллеры/ресурсы/маршруты + стриминг-download |
| **ACL deny-veto** | `c1e3cd6` | AccessResolver + Database-драйвер + grants + abilities |
| Драйверы Gate/Spatie | `2e18c02` | + Spatie v8 team-switch |
| Шеры + корзина | `83d828b` | bearer-ссылки (token-hash), public, purge-trash |
| Activity | `e529ef3` | append-only лента, one-row-per-event |
| Resumable uploads | `05d8f2e` | tus-core + S3-multipart → CAS commit |
| Outbox + webhooks | `a40d1a7` | HMAC/backoff/DLQ |
| DocDrive | `f4d6899` | DocumentHandler-реестр (новый формат без миграции), document-узлы + версии |
| Filament-админка | `927344a` | Storage/Node/Grant/Share/Activity ресурсы |
| ADR 0005-0013 + release-infra | `a106eec` | changesets + monorepo-split (dormant) + core polish |
| Vue клиент+composables | `5bc4e4e` | typed Drive client, useNodes/useUpload/… |
| Vue компоненты | `a10cdce` | VaulterDrive/Uploader/панели/DocumentNode |
| Azgard-драйвер | `015da6b` | AzgardGuard-шов (без hard-dep), fake-tested |
| Collab-backend | `985a63b` | JWT-токены + hocuspocus-вебхуки + Yjs-persist |
| Domain-restructure | `b1fe4b0` | technical roots + domain subfolders (147 файлов) |
| Collab-edge | `e5265ab` | hocuspocus-server onAuth/onStore + Vue Yjs |
| Docs overhaul | `4300472` | полная VitePress-доку, en+ru core |

**4 драйвера прав:** Database (дефолт) · Gate · Spatie · Azgard. **Всё в `packages/core`** под доменными namespace (Option A — физический split пакетов отложен).

## ⏳ ОСТАВШИЕСЯ РАБОТЫ (приоритизировано — это то, что «надо доделать»)

### P1 — Функциональное закрытие
1. ~~**Realtime-коллаборация e2e.**~~ ✅ **СДЕЛАНО** (ветка `feat/collab-e2e-stand`, 3 коммита, гейт зелёный: pint+phpstan0+pest 554+types100%+hub 13+vue 79). In-repo стенд: `testbench.yaml` + `packages/core/tests/E2E/` поднимают живой Laravel-хост (`vendor/bin/testbench serve`), заголовочная тест-аутентификация `X-E2E-User`, `/e2e/setup` сеет storage+документ-узел+гранты, `/e2e/snapshot` проверяет персист; `e2e/` = Vite-playground (реальный `@vaulter/vue useNodeCollaboration` + TipTap Collaboration) + Playwright-спека (2 браузера) + `scripts/headless-smoke.mjs` + docker-compose(postgres+laravel+hub+vite)+README; Makefile-цели `e2e-*`. **Проверено 3 способами** (headless smoke, live Playwright-MCP демо, committed Playwright 2/2): JWT верифицируется вебхуком, `readOnly` давится на живом сокете, debounced-снапшот падает в `v_node_document_versions.yjs_state`. **2 реальных бага** найдены+починены+регрессии: (а) контроллер не нормализовал `node_id`==имя комнаты от хаба → 404, снапшот терялся; (б) hocuspocus-server делал `new Server()` вместо `new Hocuspocus()` → хаб падал на старте. **Драйвер: только Hocuspocus** (см. P4 #15 и `refactor(collab): default to hocuspocus`) — Reverb для коллаборации НЕ используется, он для уведомлений/бродкаста. Остаточно: `.env.example` hocuspocus-server (был permission-protected) — заметка живёт в README; полноценная standalone-упаковка хаба — P4 #15.
2. **Azgard — реальная интеграция (не fake).** Сейчас `AzgardPermissionResolver` тестируется против `Vaulter\Contracts\Access\AzgardGuard` + `FakeAzgardGuard`. Нужно: реальный адаптер, делегирующий в AzGuard `hasScopedPermission` / Corex `TenantContextGuard::hasPermissionIn`; выравнивание `vaulter.ids.default=ulid` + `corex.ids.strategy=Ulid` + `AZ_GUARD_MORPH_TYPE=ulid`; регистрация `workspace_type`/`owner_type` как Corex морф-алиасов; интеграционные Pest-тесты с реально подключёнными `axioma-studio/azguard` + `corex` (path-repo dev-deps) ИЛИ в хосте FlexCRM. Возможно — отдельный пакет `vaulter-permissions-azgard` (см. карту пакетов §«Карта пакетов»). Заменить плейсхолдер `App\Vaulter\AllowAuthenticatedResolver` во FlexCRM.
3. ~~**Access-aware листинг (core-seam).**~~ ✅ **СДЕЛАНО** (PR #23 + follow-up). `NodeReadRepository::queryForUser()`/`constrainToAccessible()` сужают листинг к grant-reachable candidate-set (SUPERSET: без deny-вето/inherit-cutoff в SQL — точная супрессия отложена в H.4 materialised effective-grants read-model). Ворота `grantFilteringApplies()` кейсуют на КАПАБИЛИТИ `GrantAuthoritativeDriver::isGrantAuthoritative()` (НЕ на строку-ключ драйвера): standalone DatabasePermissionResolver = authoritative → узкий листинг; gate/spatie/azgard/null или host-сабкласс с role-lane/override → листинг ОСТАЁТСЯ ОТКРЫТЫМ (роли хоста рулят, grant-id-констрейнт ложно скрыл бы узлы). Провод во ВСЕ листинг-пути: NodeController `index`/`children`/`search`/`trashed` (narrowToAccessible ДО пагинации — курсор честный) + `shared` (sharedWith → EXACT re-resolve `filterViewable`) + Filament `NodeResource::constrainToAccessible` (тот же candidate-SQL → API и таблица консистентны). Per-ACTION authz неизменно EXACT через AccessResolver на show/мутациях. `StorageResource.abilities` наполнен из AccessResolver (view/create_folder/upload/manage, null-actor/no-root → all-false read-only shell). Тесты: AccessAwareListingTest + StorageShowAuthorizationTest (25 зелёных). Остаток — H.4 (кэш/материализация, «когда профайлинг потребует»).

### P2 — Релиз и упаковка
4. **Активировать release-pipeline.** Создать split-репо `axioma-studio/vaulter-core`(+`-filament`, позже `-media/-drive/-docdrive`), завести secret `GH_ACCESS_TOKEN`, зарегистрировать на Packagist; первый changeset + релиз; опубликовать `@vaulter/vue` в npm; собрать+запушить образ `ghcr.io/axioma-studio/vaulter-hocuspocus`. Файлы готовы: `.github/workflows/{release-npm,monorepo-split}.yml`, `.changeset/`.
5. **Физический split пакетов (опц., Option B).** Вынести `vaulter-media` (CAS + per-file ACL, standalone — владелец это хотел как переиспользуемый), затем `vaulter-drive`/`vaulter-docdrive` из core в отдельные composer-пакеты (path-repo + свои composer.json + ServiceProvider). Сейчас всё в `packages/core/src/{Cas,Drive,Documents,…}` под namespace — извлечение механическое. Добавить per-package CI-джобы.
6. **`vaulter-medialibrary-bridge`** (опц.) — входной мост: импорт spatie/laravel-medialibrary media → v_nodes/v_blobs. Не построен.

### P3 — Документация
7. **Полный ru-перевод** доков. Переведён core-путь (introduction/quick-start/installation/configuration/collaboration + concepts storage-panels/authorization). Остальное (`/reference/*`, recipes, filament, vue, internals, остальные guide) — en. Допереводить + донастроить ru-сайдбар.
8. (опц.) Гранулярная IA доков (`/concepts//usage//api/` split) — сейчас плоский `docs/guide/*` + `docs/reference/*`, билдится; разбивать только если нужно.

### P4 — Легаси, масштаб, мелочи
9. **Легаси DocVault-путь.** `v_documents/v_document_*` + модели `Models/Documents/{Document,DocumentVersion,DocumentFolder,DocumentComment,DocumentPermission,DocumentShare}` + их тесты ещё живы (intact). Решить: strangler-бэкфилл `v_documents → v_nodes` и депрекейт, или оставить. Мост для FlexCRM legacy editor-collection (MediaLibrary).
10. **ACL-кэш (H.4).** Сейчас on-read CTE-обход предков. При нагрузке добавить version-stamped кэш (`acl_version/tree_version/subject_version/model_version`, length-prefixed ключ — НЕ `:`-склейка, CVE-2026-48096) + материализованный effective-grants / ltree-closure read-model. Не построено (по плану — «когда профайлинг потребует»).
11. ~~**CAS — добить.**~~ ✅ **СДЕЛАНО** (ветка `feat/cas-orphan-reconcile`): orphan-object disk-walk в `vaulter:reconcile-blobs` (`4d581d9`); prune settled-outbox `vaulter:prune-webhooks` (`bd11d8d`); **PG-линия CAS-тестов** (`5a9673f` + bug-fix `b245b19`) — джоб `test-pgsql` (postgres:16-сервис, pdo_pgsql) гоняет `packages/core/tests/Cas` на реальном Postgres, где advisory-xact-lock / FOR UPDATE / SERIALIZABLE / gin(jsonb_path_ops) действительно исполняются; `TestCase` резолвит коннект из `DB_CONNECTION=pgsql` (иначе sqlite :memory:); 4 ранее-skipped prune-теста теперь исполняются, sqlite-премисные скипаются симметрично. **Прогон на живом PG выловил 2 реальных PG-бага** (невидимых на sqlite): миграция падала — `v_activity.context`/`v_node_document_versions.content` были `->json()`, а gin использует `jsonb_path_ops` (только jsonb) → `->jsonb()`; `SET TRANSACTION ISOLATION LEVEL SERIALIZABLE` падал (25001) во вложенной транзакции → guard `transactionLevel <= 1`. **Остаток (follow-up, НЕ P4 #11):** весь гетерогенный suite на PG (Document/Ulid/Spatie/TablePrefix — каждому нужна своя схема; на персистентном PG требуется per-test `migrate:fresh`, на :memory: мигрируется заново автоматически).
12. **DocDrive — форматы:** `UniverHandler` (XLSX) сейчас заглушка за флагом `spreadsheets` — доделать (single-user); опц. OnlyOffice/Collabora handlers (BYO-лицензия); PDF/DOCX exporters (seam есть).
13. **Vue UX:** move/copy используют `window.prompt`-плейсхолдер — заменить на пикер папки-цели. Filament: 1 skipped тест (Filament5+Livewire nested-render snag) — разобраться.
14. **Прочее из плана:** `vaulter-livewire` (нативные компоненты — future); hash-chaining/партиционирование activity; домержить любые `// M2/seam`-комментарии (storage-scope authz seams в StorageController).
15. **Hocuspocus-server = внешний переиспользуемый Docker-образ (НЕ копировать в каждый проект).** ✅ **СДЕЛАНО, КОД-РЕВЬЮ ПРОЙДЕНО** (ветка `feat/hocuspocus-external-image`, 5 коммитов). Хаб → дистрибутив: опубликованный `ghcr.io/axioma-studio/vaulter-hocuspocus:<ver>`, потребители ссылаются `image:` (не вкладывают исходники), конфиг — только env, секреты не печём; образ бэкенд-агностичен (авторизацию/персист делегирует вебхукам конкретного Laravel). Включает **security-hardening** (CSWSH/origin-allowlist, wss/TLS, maxPayload, Throttle, graceful shutdown, non-root, `npm ci` из lockfile), env-расширение, отдельную docs-страницу и CI-publish. **Детальный план + полный security-чеклист → «Addendum A» в конце этого файла.** (Закрепляет вывод P1.1: collab = Hocuspocus; Reverb — для уведомлений, не для коллаборации.)
    - **Реализация:** `092b61b` (security-harden) + `6b6a066` (external-image distribution).
    - **2 раунда multi-agent код-ревью:** 1-й — `4a20ea3` (3 находки: NODE_ENV-гейт fail-closed, v4-Origin read, CI-provenance дедуп). 2-й — `140b595` (8 находок, adversarially verified, 3 false-positive отброшены), поймал **2 HIGH, пропущенные 1-м раундом**: (а) `maxPayload`/`perMessageDeflate` шли как config-КЛЮЧ `websocketOptions`, который @hocuspocus/server **v2.15 ИГНОРИРУЕТ** (ws оставался на дефолтных ~100 MiB!) → фикс через 3-й арг `server.listen()`, доказано рантайм-пробой (4 MiB применяется) + регресс-тест с negative-control; (б) **throttle спуфится** через клиентские `X-Real-IP`/`X-Forwarded-For` (onUpgrade-strip НЕ доходит до throttle в 2.15 — пробовано) → compose биндит WS-порт на `127.0.0.1`, доки требуют reverse-proxy ПЕРЕЗАПИСЫВАТЬ IP-заголовки (nginx `$remote_addr` / Traefik). Плюс: строгая int-валидация (fail-fast на typo + range-checks), `npm audit` CI-гейт, README→quick-ref, ws-hardening тест. **37 node:test** (было 30), npm check ✓, docker build ✓, prod fail-closed ✓, non-root uid1000 ✓, graceful shutdown ~0.45s ✓, npm audit 0 vuln ✓.
    - **Остаток:** реальный publish образа в ghcr = **P2 #4** (нужен тег `vaulter-hocuspocus-v*` + secret); `hocuspocus-server/.env.example` (NIT) — permission-protected, исправленный контент предложен на ручной apply.

> **Как продолжить после /clear:** прочитать эту секцию + соответствующий раздел §1–§12 / H.x по теме; работать тем же ритмом (workflow: implement → review[PHPStan/Pint/Pest+канон] → fix-loop → независимая verify → тихий коммит); гейты должны оставаться зелёными.

---

## Context — зачем это

`vaulter` только что переименован из DocVault (commit `ac140fc`). Сегодня это **документо-центричный** пакет: TipTap-документы, версии, коллаборация (Reverb/Hocuspocus), таблицы `v_documents/v_document_folders/v_document_versions/v_document_permissions/v_document_comments/v_document_shares`. Архитектура зрелая и уже совпадает с каноном экосистемы: swappable-модели, `id_type`, опц. `tenant_id`, `table_prefix`, контракты `PermissionResolver/StorageAdapter/ShareTokenGenerator/DocumentExporter/CollaborationDriver`, read/store-репозитории, `final readonly` Actions, типизированный `Support\Config`, `VaulterManager`+фасад, типизированный Vue-клиент.

**Цель ре-платформинга:** превратить Vaulter в **зонтичный монорепо пакетов**, образующих встраиваемый аналог Google Drive / Яндекс.Диска, который продаётся и как самостоятельный продукт. Хранилище — полиморфное/ownable, tenant-aware, с виртуальным деревом папок (папки живут в БД, **не** на диске), физическими файлами на Flysystem (local/S3), **доступом на уровне файла/папки**, гибкой ролевой моделью (Azgard сейчас, Spatie/Gate опц.), Filament-админкой, Vue-UI (Livewire позже). Документы — сменный движок обработки по расширению.

**Главная дыра сегодня:** `StorageAdapter` — нооп; нет обобщённого файлового узла, единого дерева, блобов, дедупа, ACL на папку, версий бинарных файлов. Это и есть основной объём.

**Главная идея, которую обязательно перенять у chatom — ПАНЕЛИ.** chatom доказал, что *ось хранения/изоляции* (панель: какие физ. таблицы, модели, id-стратегия, фиче-флаги) чисто отделяется от *оси поведения* (тип канала). Vaulter берёт эту механику целиком: **Storage Panel** — хранилище, привязываемое к ЛЮБОЙ сущности хоста, со своей картой таблиц/моделей/фиче-флагов/id-стратегии и сгенерированными миграциями. Это ровно «хранилище к любой сущности», которое нужно владельцу.

---

## Зафиксированные решения (Decision Table)

| # | Решение | Выбор | Обоснование | Обратимо |
|---|---|---|---|---|
| D1 | Зонтичный бренд | **Vaulter** (вместо DocVault) | namespace `Vaulter\`, composer `axioma-studio/vaulter-*`, npm `@vaulter/*`, префикс `v_`, конфиг `vaulter.php` | нет |
| D2 | Продуктовые поверхности | **Vaulter Drive** (UX файлов/папок) · **DocDrive** (движок редактора) · **Vaulter Media** (CAS-файлы) | три имени = три домена, версионируются независимо | да |
| D3 | Файловый слой | **standalone-пакет `vaulter-media`**: CAS поверх Flysystem + per-file ACL | развязка байт+дедуп+GC от дерева; спроектирован «схлопнуться» обратно в Drive, если шов лишний | да (схлопывается) |
| D4 | Media Library | **НЕ строить на spatie/laravel-medialibrary**; только опц. входной мост | medialibrary — conversion-centric и привязана к одной host-модели; Drive нужны CAS-дедуп, ref-count GC, версии, ACL — другое ядро | да (мост аддитивен) |
| D5 | Дерево | **adjacency list (`parent_id`) + рекурсивный CTE** (`staudenmeir/laravel-adjacency-list`) | O(1) move/rename (один UPDATE) vs переписывание materialized-path у Nextcloud | да (опц. ltree/closure read-model) |
| D6 | Движок прав | **драйверы поверх одного контракта `PermissionResolver`**: `database` (дефолт), `azgard`, `spatie`, `gate` | хост берёт свою AuthZ; Database-драйвер self-contained для zero-dep | да |
| D7 | ACL-модель | per-node `v_node_grants`: subject-морф × `level` × bitmask × **`allow\|deny`** × `scope(item\|subtree)`, наследование по предкам с cut-off `inherit_grants` | NC-bitmask + Drive-роли + enterprise-deny; Drive-режим = просто не выдавать deny | да |
| D8 | Tenancy | **host-owned** — только nullable `tenant_id`; пакет **никогда не скоупит** | как ADR-0003; tenant-aware подкласс модели + global scope — забота хоста | нет |
| D9 | Дефолтный PK | **ulid** по умолчанию; стратегии `bigint\|ulid\|uuid7` за `IdStrategy` | ulid стыкуется с Corex/Azgard morphs ( key alignment); uuid7 — для native PG `uuid`; bigint — legacy. Классы портируются из chatom | да (выбор на панель) |
| D10 | Приоритет фаз | **Drive-first** (файлы/папки/блобы/ACL), DocDrive — второй, Yjs-коллаб — третий | бинарный диск — больший объём и текущая дыра схемы; документы эволюционируют из `v_documents` | да |
| D11 | **Storage Panels = конфиг-конверты, БЕЗ доп. миграций** | Панель — именованная конфигурация (host-модель + фиче-флаги + драйвер прав + handler'ы форматов + хуки + id-стратегия), а НЕ генератор таблиц. По умолчанию все панели и все хранилища живут в ОДНОЙ shared-схеме `v_*`, различаясь дискриминатором `panel` и полиморфным `owner_type/owner_id` на `v_storages`. **Привязка хранилища к ЛЮБОЙ сущности = ноль миграций.** ГЛАВНАЯ идея | универсально; не дублируем громоздкие миграции под каждую сущность (прямая претензия владельца к chatom) | нет |
| D12 | Per-panel физ. таблицы — ОПЦ. продвинутый режим | `->tablePrefix()/->tables()` + `vaulter:panel:migrations` генерят отдельные таблицы ТОЛЬКО для редких схемно-расходящихся доменов (типизированные доменные FK, изолированный vacuum/партиционирование, code-time, ≤~10–15). Большинство потребителей этого НЕ касаются | вердикт chatom F2: per-panel таблицы — тяжеловес с ops-ценой, а не дефолт; модель Nextcloud (один `oc_filecache`, дискриминатор `storage`) | да |
| D13 | Гибкость | `extra_data jsonb` на каждой доменной таблице + swappable models + config-cache-safe FQCN-регистрация | escape-hatch chatom (`HasExtraData`); без миграций под метаданные хоста | нет |
| D14 | Надёжность | перенести из chatom **outbox + webhooks** 1:1 (`v_outbox`, `v_webhook_endpoints`, `v_webhook_deliveries`) | фан-аут событий (activity/share/version) без write-time связности | да |
| D15 | Репозиторий | **один git-репо, два пакет-менеджера**: Composer path-repos + subtree-split (PHP), npm workspaces + Changesets (JS) | механика Filament (split) + Inertia (workspaces); репо уже наполовину так устроен | да |
| D16 | Резюмируемая загрузка | first-class `v_upload_sessions` (+`v_upload_parts`): S3-multipart **и** tus.io | большие бинарники; по завершении собираются в `v_blobs` | да |
| D17 | Корзина | **колонки на `v_nodes`** (`trashed_at/trashed_by/trashed_from_parent_id`), опц. сателлит `v_trashed_items` | trash-вью без join; partial-unique сиблингов естественно исключает trashed | да |
| D18 | Дедуп-scope | **per-tenant по умолчанию** (`unique(tenant_id, sha256)`), `global`/`storage` — конфиг | без утечки факта существования файла между тенантами (Seafile per-repo) | да (конфиг) |
| D19 | Коллаборация | Yjs/Hocuspocus за `CollaborationDriver`; `yjs_state bytea` на `v_document_versions` | realtime опц.; `@vaulter/hocuspocus-server` уже есть (Docker) | да |
| D20 | Документация | текущие `docs/` = **legacy к ревизии**; ADR 0005+ для ре-платформинга; доки обновляются **в каждой фазе** + финальная консолидация | старые гайды описывают DocVault; нужна перепись по новейшим наработкам (как у chatom) | нет |
| D21 | **Расширяемость (хуки + реестры + swappable-всё)** | Новый формат документа = handler-класс + регистрация (БЕЗ миграции). Реестры `DocumentHandlerRegistry`/`PanelRegistry`/`StorageTypeRegistry`; swappable models/drivers(storage/permission/collaboration)/strategies/exporters/token-generator; fill/present-хуки на панель; pipeline-pipes (ingest/move/create); события парами intent/fact; `extra_data jsonb`; типизированные enum/VO | новые форматы/поведение/интеграции встраиваются без правок ядра и без миграций — главное требование владельца по гибкости | нет |

---

## Раздел 1 — Философия

### 1.1 Что Vaulter ЕСТЬ
Встраиваемая **виртуальная файловая система для Laravel**: SQL-плоскость метаданных (авторитетное дерево, имена, размеры, ACL, версии, шеры) поверх сменной байт-плоскости (Flysystem/объектные хранилища), как набор composer+npm пакетов, монтируемых хостом на свои сущности. Это модель `oc_filecache` Nextcloud и «всё — узел с parents + permissions» Google Drive, пересобранная как swappable, мульти-тенант, open-core Laravel-библиотека.

### 1.2 Чем Vaulter НЕ является
- **Не SaaS / не приложение.** Нет таблицы users, auth, enforcement тенантности — это хост. `actor()`-колонки несут ссылки на пользователей хоста **без DB FK**.
- **Не spatie/laravel-medialibrary** (не collection+conversion-centric, не «один файл — одна host-модель»). D4.
- **Не sync-сервер.** ETag-вверх-по-дереву — опц. read-model, не встроенный WebDAV-демон.
- **Не диктует AuthZ.** Права — через выбранный драйвер (D6).
- **Не переизобретает blob-store.** Контент-адресует листовые байты (Git/Dropbox sha256), но НЕ реализует сериализованные tree-объекты Git/Seafile — дерево = SQL `parent_id`.

### 1.3 ГЛАВНАЯ ИДЕЯ — Storage Panels поверх виртуальной ФС
> **Vaulter «Storage Panel» — хранилище, привязываемое к ЛЮБОЙ сущности хоста, заданное класс-провайдером, который ты наследуешь. В дефолте панель — это КОНФИГ-конверт (host-модель + фиче-флаги + драйвер прав + handler'ы форматов + id-стратегия + хуки fill/present), работающий поверх ОДНОЙ общей схемы `v_*` — без единой доп. миграции. Собственные физ. таблицы — опциональный продвинутый режим, не дефолт.**

Маппинг chatom → Vaulter:

| chatom | Vaulter |
|---|---|
| `channel` (контейнер) | **`storage`** (корень диска, смонтированный на сущность хоста) |
| `message` (уже `parent_id`-дерево!) | **`node`** (дерево file\|folder\|document) |
| `member` (фича `participants`) | **`node_grant`** (фича `grants`) |
| `attachment` | **`blob`** (контент-адресуемый) |
| `reaction/pin/mention` | → **`file_version` / `node_share` / `tag`** |
| `channelType` (ось поведения) | **`storageType`** (пресет политики: private/team/public) |
| `extra_data` jsonb | идентично |

**Два режима хранения — и это прямой ответ на вопрос «надо ли дублировать миграции под каждую сущность»:**

1. **Дефолт (универсально, БЕЗ доп. миграций) — 99% случаев.** Одна общая схема `v_*` обслуживает все хранилища и все панели сразу. «Хранилище» = строка в `v_storages` с полиморфным `owner_type/owner_id` → монтируется на ЛЮБУЮ сущность хоста (`Vaulter::for($project)->storage()`, `->for($task)`, `->for($organization)`, `->for($user)`) без единой миграции. Узлы/блобы/гранты/шеры различаются по `storage_id` (как `oc_filecache.storage` у Nextcloud) и по дискриминатору `panel`. Несколько разных «дисков» (даже разной конфигурации) сосуществуют в одной схеме. **Никаких таблиц на сущность.**
2. **Опц. продвинутый режим (отдельные таблицы) — редко.** Если домен схемно-расходящийся (нужны типизированные доменные колонки/FK, изолированный vacuum, отдельное партиционирование), панель объявляет `->tablePrefix()/->tables()` и тогда `vaulter:panel:migrations` генерит ей собственный набор таблиц. Это **opt-in тяжеловес** (вердикт chatom F2), документируется как осознанный выбор, НЕ дефолт. Громоздкость chatom (много миграций на панель) — именно этот режим; мы выносим его в исключение.

Виртуальная ФС — *контент* любой панели; панель — *конфиг-конверт* вокруг неё. По умолчанию конверт ничего не стоит по схеме.

### 1.4 Open-core
- **Ядро (MIT):** `vaulter-core` (панели, схема, дерево, ACL-контракт+Database-драйвер, версии, шеры, корзина, activity, outbox/webhook), `vaulter-media` (CAS + per-file ACL), `@vaulter/client`.
- **Открытые адаптеры:** `vaulter-filament`, `@vaulter/vue`, `@vaulter/hocuspocus-server`.
- **Коммерческий шов (позже):** продвинутая presence-коллаборация, retention/legal-hold/e-discovery, федерация, antivirus/DLP — всё как **драйверы/pipes**, шов = контракт, не форк.

### 1.5 Честная позиция по масштабу
- **Огромные диски:** `blobs`+`file_versions`+ref-count-GC дают сквозной дедуп и иммутабельную историю; object-key-из-id → переименование трогает только БД. Миллионы блобов на S3/MinIO.
- **Глубокие деревья:** adjacency + CTE быстры в режиме 10³–10⁵ узлов/дерево, глубина ≤~20 (где живут файл-менеджеры). **Платим на чтении.** Для млн-узловых деревьев или горячего резолва прав — добавить `ltree` (PG) / closure-table (портируемо) read-model **только когда профайлинг потребует** (D5).
- **Острые углы (честно):** rollup размера папки на write-пути → делаем лениво (`-1` + фоновый пересчёт, урок bloat Nextcloud); фан-аут activity → **одна строка события + резолв получателей на чтении** (анти-NC-взрыв); пересчёт subtree-ACL → материализованный read-model (опц.).

### 1.6 Принципы (любим/избегаем)
**Любим:** контент-адресация листовых байт (Git/Dropbox); object-key = id узла/блоба (NC object store); CRUDS-bitmask (NC); один schema-хелпер драйвит каждый id/ref/actor/extra_data (chatom `PanelTablesBuilder`); config-cache-safe FQCN-регистрация без closures; swappable models; иммутабельные версии на иммутабельные блобы; append-only аудит; транзакционный outbox.
**Избегаем:** materialized-path как источник истины (rename-cost NC); MD5 path_hash (MySQL-обходной, PG не нужен); int-нормализацию mimetype (преждевременно); легаси `item_source/item_target` шеров; multi-parent файлы (один `parent_id`); CDC-чанкинг сразу (старт fixed 4 MiB, Seafile-CDC позже); S3 ETag как ключ дедупа (multipart `-N` ≠ контент-хэш); per-affected-user фан-аут activity.

### 1.7 Референсы
Nextcloud `oc_filecache` (`storage/path/path_hash/parent`, `UNIQUE(storage,path_hash)`, CRUDS-bitmask, etag-вверх, `oc_storages`/`oc_mounts`) · Seafile (block/file/dir CAS, CDC) · Git (blob/tree/commit) · Google Drive v3 (folders=files+`parents[]`, permissions=`subject×role`, revisions+`keepForever`, shared drives) · Dropbox (`content_hash`) · S3/MinIO (versioning, multipart `UploadId`+parts) · chatom Panels (механика портируется) · Spatie/AzGuard config-resolve (драйверы/контракты, без closures).

---

## Раздел 2 — База данных (центральная часть)

### 2.0 Конвенция (несёт каждая таблица)
| Аспект | Правило | Хелпер |
|---|---|---|
| PK | `id` типа стратегии | `VaulterSchema::key()` → `IdStrategy` |
| FK (внутри пакета) | тип стратегии + индекс + **реальный `->foreign()->cascadeOnDelete()`** | `VaulterSchema::reference()` (расширить текущий `foreignKey()`, который не ставит DB FK) |
| Полиморф | `*_type` varchar + `*_id` тип стратегии, composite idx | `VaulterSchema::morphs()` |
| Ссылка на user хоста | nullable, **без DB FK** | `VaulterSchema::actor()` |
| Tenant | опц. `tenant_id`, nullable+idx, host-scoped, эмитится только если включён | `VaulterSchema::tenant()` |
| Гибкость | `extra_data jsonb NOT NULL DEFAULT '{}'` | `VaulterSchema::extraData()` (как `ChatomSchema`) |
| Bitmask | `unsignedInteger default 0` | `VaulterSchema::bits()` |
| Panel-дискриминатор | `string('panel')->default('default')->index()` на корнях панели | `VaulterSchema::panel()` |
| Soft-delete/timestamps | `softDeletes()` + `timestamps()` где указано | Laravel |
| Имена таблиц | `{prefix}{base}`, дефолт `v_` | `Config::table()` |
| Модели | swappable через `config('vaulter.models.*')` | `Config::*Model()` |

`{key}` = `bigint`/`char(26)`/`uuid` по стратегии. Классы id-стратегий портируются из chatom (`IdStrategy` контракт + `AutoIncrementIds`/`UlidIds`/`Uuid7Ids` + `IdStrategyResolver`).

### 2.1 `v_storages` — ownable полиморфный контейнер диска
(NC `oc_storages`+`oc_mounts` в одну строку, смонтировано на морф хоста.)

| колонка | тип (PG) | null | default | индекс | FK | назначение |
|---|---|---|---|---|---|---|
| id | `{key}` | нет | — | PK | — | PK |
| panel | varchar | нет | `'default'` | idx; `[panel,status]` | — | дискриминатор панели |
| tenant_id | `{key}` | да | null | idx | — | опц. тенант |
| owner_type | varchar(255) | нет | — | `[owner_type,owner_id]` | — | морф: класс host-сущности |
| owner_id | `{key}` | нет | — | (там же) | — | морф id сущности |
| name | varchar(255) | нет | — | — | — | отображаемое имя |
| slug | varchar(160) | да | null | uniq `[tenant_id,slug]` partial | — | URL-хэндл |
| root_node_id | `{key}` | да | null | uniq | →`v_nodes.id` | указатель корневой папки |
| disk | varchar(64) | нет | `'default'` | — | — | Flysystem-диск для блобов |
| storage_type | varchar(32) | нет | `'private'` | idx | — | пресет политики (private\|team\|public) |
| quota_bytes | bigint | да | null | — | — | мягкая квота (null=∞) |
| used_bytes | bigint | нет | 0 | — | — | кэш rollup размера |
| status | varchar(16) | нет | `'active'` | idx | — | active\|readonly\|archived\|disabled |
| default_node_permissions | int | нет | 31 | — | — | bitmask владельцу поддерева |
| extra_data | jsonb | нет | `'{}'` | gin(опц) | — | расширения хоста |
| created_by | `{key}` | да | null | — | actor | создатель |
| deleted_at / created_at / updated_at | timestamptz | да | null | — | — | soft-delete + timestamps |

Индексы: PK; `[owner_type,owner_id]`; `[tenant_id,status]`; `UNIQUE[tenant_id,slug] WHERE deleted_at IS NULL`; `root_node_id` uniq. **Всегда создаётся** (корень панели).

### 2.2 `v_nodes` — единое дерево folder\|file\|document (ЦЕНТР)
Одно adjacency-дерево несёт все три kind. Заменяет дерево `v_documents`+`v_document_folders`.

| колонка | тип | null | default | индекс | FK | назначение |
|---|---|---|---|---|---|---|
| id | `{key}` | нет | — | PK | — | PK |
| panel | varchar | нет | `'default'` | idx | — | дискриминатор |
| tenant_id | `{key}` | да | null | idx | — | опц. тенант |
| storage_id | `{key}` | нет | — | idx | →`v_storages.id` cascade | владелец-контейнер |
| parent_id | `{key}` | да | null | idx | self→`v_nodes.id` | **adjacency** (null=корень) |
| kind | varchar(16) | нет | — | idx | — | folder\|file\|document\|shortcut |
| name | citext/varchar(255) | нет | — | (в uniq) | — | имя в родителе |
| mime_type | varchar(191) | да | null | idx | — | кэш для file/doc |
| extension | varchar(32) | да | null | — | — | кэш расширения |
| size_bytes | bigint | нет | 0 | — | — | file→head size; folder→rollup (лениво) |
| current_version_id | `{key}` | да | null | idx | →file/doc_versions | указатель head |
| file_id | `{key}` | да | null | uniq | →`v_files.id` | если kind=file (1:1) |
| position | int | нет | 0 | — | — | ручной порядок сиблингов |
| is_pinned | boolean | нет | false | — | — | starred |
| inherit_grants | boolean | нет | true | — | — | **cut-off наследования ACL** |
| shortcut_target_id | `{key}` | да | null | idx | →`v_nodes.id` | цель shortcut |
| owner_id | `{key}` | да | null | idx | actor | владелец |
| path_cache | text/ltree | да | null | gist(опц) | — | опц. materialized-path read-model |
| depth | smallint | да | null | — | — | опц. кэш глубины |
| extra_data | jsonb | нет | `'{}'` | gin(опц) | — | метаданные хоста/документа |
| trashed_at | timestamptz | да | null | (partial) | — | **soft-trash (≠ soft-delete)** |
| trashed_by | `{key}` | да | null | — | — | кто отправил в корзину |
| trashed_from_parent_id | `{key}` | да | null | — | — | исходный родитель для restore |
| created_by / updated_by | `{key}` | да | null | — | actor | акторы |
| deleted_at / created_at / updated_at | timestamptz | да | null | — | — | permanent soft-delete + timestamps |

**Индекс-план (критично):**
- PK; `(parent_id)` — CTE-спуск; `(storage_id, parent_id)` и `(storage_id, parent_id, position)` — горячий листинг.
- **Уникальность сиблингов c soft-delete (PG):** `CREATE UNIQUE INDEX v_nodes_sibling_uniq ON v_nodes (parent_id, lower(name)) WHERE deleted_at IS NULL AND trashed_at IS NULL;` (`citext` снимает `lower()`).
- **MySQL-портируемость:** stored generated `parent_active = IF(deleted_at IS NULL AND trashed_at IS NULL, CONCAT(parent_id,':',LOWER(name)), NULL)` + `UNIQUE(parent_active)`.
- `(storage_id, kind, trashed_at)`; partial `(storage_id, trashed_at) WHERE trashed_at IS NOT NULL` (trash-вью); `gin(to_tsvector('simple', name))` (поиск).

**Дивергенции от NC `oc_filecache` (обоснованы):** (1) `parent_id`+CTE вместо `path`+`path_hash` → O(1) move; (2) явный `kind` (folder/file/document в одном дереве); (3) байты вынесены в `blobs`/`file_versions` (дедуп+история). **Всегда создаётся.**

### 2.3 `v_blobs` — контент-адресуемая иммутабельная нагрузка (дедуп+GC)

| колонка | тип | null | default | индекс | FK | назначение |
|---|---|---|---|---|---|---|
| id | `{key}` | нет | — | PK | — | внутренний хэндл |
| tenant_id | `{key}` | да | null | (в dedup uniq) | — | если `dedup_scope=tenant` |
| storage_id | `{key}` | да | null | (в dedup uniq) | →`v_storages.id` | если `dedup_scope=storage` |
| hash_algo | varchar(16) | нет | `'sha256'` | — | — | запас под миграцию алго |
| sha256 | char(64) | нет | — | uniq | — | **контент-адрес** |
| size_bytes | bigint | нет | — | idx | — | длина |
| disk | varchar(64) | нет | `'default'` | — | — | Flysystem-диск |
| storage_key | text | нет | — | uniq | — | **scope-квалифиц.** `<scope>/ab/cd/<sha256>` (H.3 — scope-free путь = кросс-scope потеря данных) |
| status | varchar(12) | нет | `'pending'` | idx | — | pending\|committed\|deleting\|deleted |
| ref_count | bigint | нет | 0 | (в GC idx) | — | **кэш** счётчика (не единственная истина) |
| dedup_scope | varchar(8) | нет | `'tenant'` | — | — | global\|tenant\|storage |
| etag/md5/crc32c | varchar(64) | да | null | — | — | чек-суммы roundtrip объект-хранилища |
| chunked | boolean | нет | false | — | — | манифест-чанков vs один объект |
| encryption_key_id | `{key}` | да | null | — | — | хэндл envelope-шифрования |
| first_seen_at | timestamptz | нет | now() | — | — | создан |
| committed_at | timestamptz | да | null | — | — | durable+verified |
| delete_after_at | timestamptz | да | null | (в GC idx) | — | grace-дедлайн GC |
| extra_data | jsonb | нет | `'{}'` | — | — | метаданные бэкенда |

Индексы: PK; **dedup-uniq** = `(sha256)`/`(tenant_id,sha256)`/`(storage_id,sha256)` по `dedup_scope` (D18); uniq `(storage_key)`; **GC partial** `(delete_after_at) WHERE status='committed' AND ref_count=0`; `(status)`, `(size_bytes)`.
**GC (two-phase):** скан `committed ∧ ref_count=0 ∧ delete_after_at<now()` → переверка отсутствия живого ребра в `v_file_version_blobs` → `deleting` → удалить байты → `deleted` (идемпотентный tombstone). `ref_count` — кэш; **истина пересчёта = таблица рёбер.** Фича `blobs`.
Опц. суб-файловый дедуп: `v_blob_links(parent_blob_id, child_blob_id, ordinal, offset_bytes, length_bytes)` — старт fixed 4 MiB (Dropbox), CDC (Seafile) позже.

### 2.4 `v_files` + `v_file_versions` — логический файл ↔ иммутабельный блоб
**`v_files`** (мутабельный хэндл, 1:1 с node kind=file): `id`, `tenant_id`, `node_id` uniq →`v_nodes` cascade, `current_version_id` →`v_file_versions`, `mime_type`, `size_bytes` (кэш head), `version_count` (кэш для прунинга), `extra_data`, `created_by`, soft-delete+timestamps.

**`v_file_versions`** (иммутабельный):

| колонка | тип | null | default | индекс | FK | назначение |
|---|---|---|---|---|---|---|
| id | `{key}` | нет | — | PK | — | id версии |
| tenant_id | `{key}` | да | null | idx | — | тенант |
| file_id | `{key}` | нет | — | (в uniq) | →`v_files.id` cascade | родитель |
| version_no | int | нет | — | uniq | — | **монотонно на файл** |
| blob_id | `{key}` | да | null | idx | →`v_blobs.id` | одно-блобовая нагрузка |
| manifest_blob_id | `{key}` | да | null | idx | →`v_blobs.id` | чанк-манифест (альт) |
| content_sha256 | char(64) | нет | — | idx | — | хэш всего файла |
| size_bytes | bigint | нет | — | — | — | размер версии |
| mime_type | varchar(191) | да | null | — | — | заявленный mime |
| original_name | varchar(255) | нет | — | — | — | имя при загрузке |
| status | varchar(12) | нет | `'pending'` | idx | — | pending\|committed\|superseded\|deleted |
| keep_forever | boolean | нет | false | — | — | исключение из прунинга |
| extra_data | jsonb | нет | `'{}'` | — | — | EXIF/трансформы |
| created_by / created_at / committed_at | — | да | — | — | actor | автор + времена |

Индексы: PK; **uniq `(file_id, version_no)`**; `(file_id, status)`; `blob_id`; `manifest_blob_id`; `content_sha256`.
**Таблица рёбер (истина GC):** `v_file_version_blobs(file_version_id, blob_id, role[data\|manifest\|chunk], ordinal, offset_bytes, length_bytes)` PK `(file_version_id, blob_id, role, ordinal)`.
**Commit (одна транзакция):** upload → blob(ы) `pending` → verify sha256 → upsert blob по dedup-ключу (хит ⇒ reuse + ref_count++) → version `pending` → рёбра → blob+version `committed` → перенаведение `v_files.current_version_id` & `v_nodes.current_version_id` → прежний head `superseded`. Фича `versions`.

### 2.5 `v_document_versions` (+`yjs_state`) — путь DocDrive
Для node kind=document (TipTap/Yjs). Эволюция текущего `v_document_versions`; документы минуют блобы (мелкий структурный JSON).

| колонка | тип | null | default | индекс | назначение |
|---|---|---|---|---|---|
| id | `{key}` | нет | — | PK | id версии |
| tenant_id | `{key}` | да | null | idx | тенант |
| node_id | `{key}` | нет | — | (в uniq) →`v_nodes` cascade | узел-документ |
| version_no | int | нет | — | uniq | монотонно на узел |
| content | jsonb | да | null | gin(опц) | снапшот TipTap/ProseMirror |
| yjs_state | bytea | да | null | — | **бинарный Yjs CRDT-апдейт** |
| yjs_state_size | int | да | null | — | кэш длины |
| format | varchar(16) | нет | `'tiptap'` | — | tiptap\|markdown\|table\|template |
| content_hash | char(64) | да | null | — | дедуп идентичных снапшотов |
| snapshot_label | varchar(255) | да | null | — | именованная версия |
| is_autosave | boolean | нет | false | idx | автосейв vs именованная |
| status | varchar(12) | нет | `'committed'` | idx | паритет жизненного цикла |
| keep_forever | boolean | нет | false | — | исключение прунинга |
| extra_data / created_by / created_at | — | — | — | — | метаданные редактора |

Индексы: PK; uniq `(node_id, version_no)`; `(node_id, is_autosave, created_at)`; `gin(content jsonb_path_ops)`. Прунинг — `config('vaulter.versions.max')` (50), кроме `keep_forever`. MySQL: `yjs_state`→`longBlob`. Фича `document_versions`.

### 2.6 `v_node_grants` — per-node ACL с наследованием

| колонка | тип | null | default | индекс | назначение |
|---|---|---|---|---|---|
| id | `{key}` | нет | — | PK | id гранта |
| tenant_id | `{key}` | да | null | idx | тенант |
| node_id | `{key}` | нет | — | (в uniq) →`v_nodes` cascade | узел |
| subject_type / subject_id | морф | нет | — | (в uniq) | user\|role\|team |
| level | varchar(16) | нет | — | idx | reader\|commenter\|writer\|organizer\|owner |
| permissions | int | нет | 0 | — | **NC-bitmask** READ=1,UPDATE=2,CREATE=4,DELETE=8,SHARE=16,ALL=31 |
| effect | varchar(5) | нет | `'allow'` | idx | **allow\|deny** |
| scope | varchar(8) | нет | `'subtree'` | — | item\|subtree |
| inherited | boolean | нет | false | idx | true на материализованных строках (read-model) |
| inherited_from_node_id | `{key}` | да | null | idx →`v_nodes` | провенанс (Drive inheritedFrom) |
| expires_at | timestamptz | да | null | idx | тайм-боксед грант |
| extra_data / granted_by / timestamps | — | — | — | — | хост + actor |

Индексы: PK; **uniq `(node_id, subject_type, subject_id, scope, effect) WHERE deleted_at IS NULL`**; `(subject_type, subject_id)` («доступные мне»); `(node_id, effect)`; `(inherited_from_node_id)`.
**Порядок резолва (контракт):** (1) идти по предкам CTE (scoped `storage_id`), собирая direct + `scope=subtree`, **стоп на первом предке с `inherit_grants=false`**; (2) множество идентичностей субъекта (user+роли+команды от драйвера); (3) **explicit `deny` на том-же/ближе узле бьёт `allow`** (NFSv4-стиль; дивергенция от allow-only Drive); (4) иначе OR bitmask / max `level`; (5) чистый Drive = просто не выдавать `deny`. `inherited*` — опц. материализованный «effective grants» read-model; истина = direct-строки + дерево. Фича `grants`.

### 2.7 `v_node_shares` — bearer-ссылки
`id`, `tenant_id`, `node_id` idx →`v_nodes` cascade, **`token_hash` char(64) uniq** (хранить только хэш sha256), `token_prefix` idx (UX-поиск без раскрытия), `permissions` (bitmask, дефолт READ), `level`, `scope(item\|subtree)`, `password_hash?`, `require_login`, `allow_discovery`, `expires_at?` idx, `max_uses?`, `use_count`, `revoked_at?` idx, `extra_data`, `created_by`, timestamps. Индексы: uniq `(token_hash)`; `(node_id, revoked_at)`; partial `(expires_at) WHERE revoked_at IS NULL`; `(token_prefix)`. Генератор токена — `Config::shareTokenGeneratorClass()` (`RandomShareTokenGenerator`). Фича `share_links`.

### 2.8 `v_upload_sessions` (+`v_upload_parts`) — резюмируемый ingest (S3-multipart + tus)
**`v_upload_sessions`:** `id`, **`upload_id` uuid uniq** (S3 uploadId / tus resource), `tenant_id`, `storage_id`, `node_id?` (new-version), `target_parent_id?`+`target_name?` (new-file), `protocol(s3-multipart\|tus\|direct)`, `status(initiated\|uploading\|assembled\|committed\|aborted\|expired)`, `expected_sha256?`, `total_size_bytes?`, `received_bytes` (**tus-offset**), `chunk_size?`, `mime_type?`, `parts jsonb?` (inline-манифест мелких), `assembled_blob_id?`, `metadata jsonb` (tus Upload-Metadata), `extra_data`, `expires_at?` idx, timestamps. Индексы: uniq `(upload_id)`; **`(status, expires_at)`** (sweeper); `(storage_id)`.
**`v_upload_parts`** (нормализованно): `upload_session_id`→cascade, `part_no`, `byte_offset`, `size_bytes`, `part_sha256`, `etag`, `temp_blob_id`→`v_blobs`, `received_at`. **PK `(upload_session_id, part_no)`** (re-upload заменяет — S3). Фича `uploads`.

### 2.9 `v_activity` — аудит/лента (append-only)
`id` (uuid7 рек.), `tenant_id` idx, `storage_id?` idx →`v_storages`, `actor_type/actor_id` морф, `verb` idx (node.created\|moved\|trashed\|restored\|version.created\|grant.created\|share.created\|inheritance.disabled\|download), `target_type/target_id` морф, `context jsonb` gin (before/after, old/new path/role, ip), `ip inet`, `user_agent`, `request_id uuid`, `extra_data`, `created_at` idx. Индексы: `(created_at desc)`, `(actor_*,created_at desc)`, `(target_*,created_at desc)`, `(verb,created_at desc)`, `(storage_id,created_at desc)`, `gin(context)`. **Без updated_at/soft-delete** — append-only, **одна строка события, получатели на чтении**. Опц.: помесячное партиционирование, hash-chaining. Фича `activity`.

### 2.10 Корзина (D17)
**Дефолт — колонки на `v_nodes`** (§2.2 триплет). Двухстадийно: `trashed_at` (восстановимо, retention N дней) → `deleted_at` (tombstone) → физ. purge (ref_count-- → GC). Partial-uniq сиблингов исключает trashed → restore детектит коллизию имён. Опц. сателлит **`v_trashed_items`** (`node_id` uniq, `original_parent_id`, `original_path`, `trashed_by`, `retention_until` idx, `legal_hold` bool, `trashed_at`) — при per-item retention/legal-hold.

### 2.11 Слой надёжности — порт chatom 1:1 (D14)
- **`v_outbox`** — `key`, `event_id` uniq, `panel`, `topic?`, `event`, `payload json`, `available_at`, `dispatched_at?`, `attempts` 0, `status 'pending'`, timestamps; idx **`[status,available_at]`**.
- **`v_webhook_endpoints`** — `key`, `url`, `events json`, `signing_secret`, `is_active`, `last_triggered_at`, `failure_count`, timestamps.
- **`v_webhook_deliveries`** — `key`, `endpoint_id`→cascade, `event`, `payload json`, `status`, `attempts`, `next_retry_at?`, `last_error`, timestamps; idx **`[status,next_retry_at]`**.

### 2.12 Матрица конвенций
| Таблица `v_` | id | tenant | extra_data | soft-del | timestamps | panel | всегда | фича | model-key |
|---|---|---|---|---|---|---|---|---|---|
| storages | ✅ | opt | ✅ | ✅ | ✅ | ✅ | ✅ | — | storage |
| nodes | ✅ | opt | ✅ | ✅+trash | ✅ | ✅ | ✅ | — | node |
| blobs | ✅ | scoped | ✅ | GC tomb | partial | — | — | blobs | blob |
| files | ✅ | opt | ✅ | ✅ | ✅ | — | — | versions | file |
| file_versions | ✅ | opt | ✅ | immut | created | — | — | versions | file_version |
| file_version_blobs | composite | — | — | — | — | — | — | versions | (pivot) |
| document_versions | ✅ | opt | ✅ | immut | created | — | — | document_versions | document_version |
| node_grants | ✅ | opt | ✅ | ✅ p-uniq | ✅ | — | — | grants | grant |
| node_shares | ✅ | opt | ✅ | ✅ | ✅ | — | — | share_links | share |
| upload_sessions | ✅ | opt | ✅ | expiry GC | ✅ | — | — | uploads | upload_session |
| upload_parts | composite | — | — | — | received | — | — | uploads | (pivot) |
| activity | ✅ uuid7 | opt | ✅ | append | created | — | — | activity | activity |
| trashed_items (opt) | ✅ | opt | ✅ | — | trashed_at | — | — | trash | trashed_item |
| outbox | ✅ | — | — | — | ✅ | ✅ | global | reliability | outbox |
| webhook_endpoints | ✅ | — | — | — | ✅ | — | global | reliability | webhook_endpoint |
| webhook_deliveries | ✅ | — | — | — | ✅ | — | global | reliability | webhook_delivery |

### 2.13 Стратегия дерева
Старт **adjacency-only** (`HasRecursiveRelationships` на `Node`; переопределить `getParentKeyName()`/`getLocalKeyName()` под стратегии; scope `treeOf()` по `storage_id`; `withMaxDepth(n)` для мелких загрузок). Добавить **`ltree` (PG, синк observer-ом на move)** или **closure-table `v_node_closure(ancestor_id, descendant_id, depth)` (портируемо)** ТОЛЬКО когда CTE станут горячими / деревья >млн узлов.

---

## Раздел 3 — Структура папок (монорепо: PHP + npm в одном репо)

**Вердикт по «PHP+npm в одном репо»:** ОДИН git-репо, ДВА пакет-менеджера, ДВА релиз-канала. Composer читает каждый `packages/<php>/composer.json` (локально — path-repos, публикация — subtree-split → split-репо → Packagist). npm читает каждый `packages/<js>/package.json` (локально — workspaces, публикация — in-place через Changesets). Пересекаются на диске, но никогда как «один пакет». Это стандартная практика (Inertia/Livewire/Filament-assets/Spatie с `/resources/js`).

```
vaulter/                              # git-корень = источник истины
├─ composer.json                      # dev-shell (type:project, path-repos, minimum-stability:dev) — оставить
├─ package.json                       # root npm workspaces (private:true)
├─ .changeset/                        # ДОБАВИТЬ — npm-версионирование
├─ packages/
│  ├─ core/      → axioma-studio/vaulter-core      (Panels, Schema, IdStrategy, дерево Node, ACL-контракт+Database-драйвер, версии, шеры, корзина, activity, outbox/webhook, контракты, базовые модели)
│  ├─ media/     → axioma-studio/vaulter-media     (STANDALONE: CAS поверх Flysystem, v_blobs, ref-count GC, per-file ACL, upload-сессии) — зависит только от контрактов core
│  ├─ drive/     → axioma-studio/vaulter-drive      (Vaulter Drive UX: оркестрация папок/файлов, move/copy/trash, HTTP/API) — core + media
│  ├─ docdrive/  → axioma-studio/vaulter-docdrive   (DocDrive: document-узлы, TipTap JSON, yjs_state, collab-драйвер) — core
│  ├─ filament/  → axioma-studio/vaulter-filament   (Filament-ресурсы) — core (+drive/docdrive опц.)
│  ├─ medialibrary-bridge/ → axioma-studio/vaulter-medialibrary-bridge (ОПЦ. входной мост spatie media → v_nodes/v_blobs) — media + spatie (suggest)
│  ├─ client/    → @vaulter/client    (фреймворк-агностичный TS-клиент — выносим из vue/src/client.ts; полноценно — когда появится non-Vue адаптер)
│  └─ vue/       → @vaulter/vue        (Vue 3 + TipTap + Hocuspocus; зависит от @vaulter/client через workspace:^)
├─ hocuspocus-server/ → @vaulter/hocuspocus-server  (private:true, Docker, вне npm-publish; образ ghcr.io)
├─ docs/                              # VitePress (см. §8)
└─ .github/workflows/
   ├─ frontend.yml                    # ИСПРАВИТЬ: @docvault/vue → @vaulter/vue (3 вхожд.+имя job); filament.yml label
   ├─ tests.yml, filament.yml, static-analysis.yml, mutation.yml, code-style.yml
   ├─ release-npm.yml                 # ДОБАВИТЬ: changesets/action@v1 + OIDC provenance
   └─ monorepo-split.yml              # ДОБАВИТЬ: monorepo-split на тег (как Filament)
```

**Граф зависимостей:** `vaulter-core` (нет Vaulter-deps; Laravel + staudenmeir/laravel-adjacency-list) ← `vaulter-media` (+Flysystem) ← `vaulter-drive`; `vaulter-docdrive`→core; `vaulter-filament`→core(+drive/docdrive suggest); `vaulter-medialibrary-bridge`→media(+spatie suggest); `@vaulter/vue`→`@vaulter/client`.

**Tag/release (гибрид):** npm — Changesets (linked-группа `@vaulter/vue`+`@vaulter/client`), тег `@vaulter/vue@X.Y.Z`, `changeset publish` на `main`, OIDC-provenance, `build` перед publish (`dist/` gitignored, `files:["dist"]`). PHP — единый поток версии: monorepo-тег `vaulter-php-vX.Y.Z` → `monorepo-split.yml` форс-пушит чистый semver в split-репо (`vaulter-core/-media/-drive/-docdrive/-filament`), на Packagist регистрируем **split-репо**. Hocuspocus — Docker-тег `ghcr.io/axioma-studio/vaulter-hocuspocus:X.Y.Z`.

**CI (партиция по путям):** PHP-job (`packages/{core,media,drive,docdrive,filament}/**`): `composer validate`, Pint, PHPStan/Larastan, Pest (Testbench). JS-job (`packages/{client,vue}/**`): install, typecheck, build, vitest. Integration-job: playground Laravel-app (path-repo PHP + workspace JS). Release: changesets на `main`; split на PHP-теге.

**Немедленные фиксы (репо сломан после переименования):** (1) `frontend.yml` `@docvault/vue`→`@vaulter/vue`; `filament.yml` label — **высший приоритет**; (2) `.changeset/` + `release-npm.yml`; (3) пустые split-репо + `monorepo-split.yml` + Packagist; (4) решить про lock-файлы (`package-lock.json`/`composer.lock`).

---

## Раздел 4 — Конфигурация и гибкость + механизм Storage Panels

### 4.1 `config/vaulter.php`
```php
return [
    'table_prefix' => 'v_',
    'table_names' => [ 'storages'=>'storages','nodes'=>'nodes','files'=>'files','file_versions'=>'file_versions',
        'blobs'=>'blobs','document_versions'=>'document_versions','node_grants'=>'node_grants','node_shares'=>'node_shares',
        'upload_sessions'=>'upload_sessions','upload_parts'=>'upload_parts','activity'=>'activity','trashed_items'=>'trashed_items',
        'outbox'=>'outbox','webhook_endpoints'=>'webhook_endpoints','webhook_deliveries'=>'webhook_deliveries' ],
    'models' => [ 'storage'=>Storage::class,'node'=>Node::class,'file'=>File::class,'file_version'=>FileVersion::class,
        'blob'=>Blob::class,'document_version'=>DocumentVersion::class,'grant'=>NodeGrant::class,'share'=>NodeShare::class,
        'upload_session'=>UploadSession::class,'activity'=>Activity::class /* … swappable */ ],
    'ids' => [ 'default'=>env('VAULTER_ID_STRATEGY','ulid'),  // ulid|uuid7|bigint (D9)
        'strategies'=>['bigint'=>AutoIncrementIds::class,'ulid'=>UlidIds::class,'uuid7'=>Uuid7Ids::class] ],
    'panels' => [],                                    // список FQCN StoragePanelProvider (cache-safe)
    'default_panel' => DefaultStoragePanel::class,
    'migrations' => ['path'=>null],                    // выход генератора; null → database/migrations
    'extra_data' => ['enabled'=>true],
    'features' => [ 'blobs'=>true,'versions'=>true,'grants'=>true,'activity'=>true,'uploads'=>true,
        'share_links'=>false,'document_versions'=>false,'public_listing'=>false,'reliability'=>true ],
    'drivers' => [
        'storage'=>env('VAULTER_STORAGE_DRIVER','flysystem'),
        'permission'=>env('VAULTER_PERMISSION_DRIVER','database'),       // database|azgard|spatie|gate (D6)
        'collaboration'=>env('VAULTER_COLLAB_DRIVER','hocuspocus'),
        'permission_drivers'=>['database'=>DatabasePermissionResolver::class,'azgard'=>AzgardPermissionResolver::class,
            'spatie'=>SpatiePermissionResolver::class,'gate'=>GatePermissionResolver::class],
        'storage_drivers'=>['flysystem'=>FlysystemStorageDriver::class],
        'collaboration_drivers'=>['hocuspocus'=>HocuspocusDriver::class,'none'=>null] ],
    'handlers' => [ 'ingest_file'=>[/* Validate, AssembleBlob, Dedup, CommitVersion, RepointHead, RecordActivity */],
        'create_node'=>[/* … */], 'move_node'=>[/* … */] ],
    'exporters' => [ /* mime → exporter (текущий pluggable export) */ ],
    'share' => ['token_generator'=>RandomShareTokenGenerator::class,'token_bytes'=>32],
    'dedup' => ['scope'=>env('VAULTER_DEDUP_SCOPE','tenant')],          // global|tenant|storage (D18)
    'versions' => ['max'=>50], 'trash' => ['retention_days'=>30],
    'tenant' => ['enabled'=>env('VAULTER_TENANT',false),'column'=>'tenant_id'],  // host-owned (D8)
    'route' => ['prefix'=>'vaulter','middleware'=>['web']],
];
```
Типизированный `Support\Config`: `table()/model()/idStrategy()/extraDataEnabled()/featureEnabled()/driver()/permissionResolver()/storageDriver()/collaborationDriver()/pipeline()/panels()/defaultPanel()/shareTokenGeneratorClass()/dedupScope()/maxVersions()/trashRetentionDays()/tenantEnabled()/tenantColumn()/exporters()`.

### 4.2 Механизм Storage Panels (конфиг-конверт; таблицы — общие по умолчанию)

**Ключевой инвариант:** `Panel::hasOwnTables()` = false по умолчанию ⇒ панель **резолвит общие `v_*` таблицы** (`Config::table()`), миграции НЕ генерятся. `vaulter:make-panel` создаёт только класс-провайдер + (опц.) модели — **никаких миграций**. Привязка к сущности — через `v_storages.owner_*` (полиморф) и фасад `Vaulter::for($entity, panel: '…')`. Генератор миграций включается ТОЛЬКО когда панель явно объявила `->tablePrefix()/->tables()` (D12, редкий продвинутый режим) — `PanelMigrationGenerator::generate()` сам отвергает панель без собственных таблиц.

**Минимальный порт** = скопировать `Schema/*`, `Panels/{Panel, StoragePanelProvider, DefaultStoragePanel, PanelRegistry, PanelTableResolver, PanelMigrationGenerator}`, `Models/Concerns/{HasVaulterPanel, HasVaulterKey, HasExtraData}`, стабы, команды, ключи конфига, `Support\Config`, singleton-биндинг. Переписать только тела доменной схемы. `PanelMigrationGenerator` и `vaulter:panel:migrations` — часть **опц. продвинутого режима**, не дефолтного пути.

- **`Panel`** (`src/Panels/Panel.php`) — **мутабельный** fluent-builder (Filament-стиль, НЕ readonly). Сеттеры `id()/host()/grants()/withoutGrants()/storageType()/idStrategy()/tablePrefix()/tables()/models()/features()/feature()/pipes()/resource()`. Резолверы (override→prefix→global): `table($base)` (= `tableMap[$base] ?? "{prefix}_{base}" ?? config('vaulter.table_names.*')`), `model($entity)`, `idStrategyKey()`, `hasOwnTables()` (= prefix или map заданы), `featureEnabled()` (override → дефолты Vaulter: `grants` от hasGrants; `versions/blobs/tags/activity/uploads/extra_data`=true; `share_links/public_listing/document_versions`=false).
- **`StoragePanelProvider`** (abstract) — `abstract panel(Panel): Panel`; `resolve()=panel(new Panel)`; хуки-ноопы: `fillNode(StoreNodeData):array`, `presentNode(Node):array`, `pipes():array`, `booted(Panel):void`. Регистрация — **только FQCN в конфиге** (cache-safe). Дефолт `DefaultStoragePanel` = `->id('default')->storageType('private')`.
- **`PanelRegistry`** (singleton по id): `register()/registerProvider()/fromConfig(array $providers, string $default)` (дефолт первым → явные панели переопределяют) / `get()` (бросает `UnknownPanelException`) / `has()/all()/default()`. Биндинг: `singleton(PanelRegistry::class, fn()=>PanelRegistry::fromConfig(config('vaulter.panels',[]), config('vaulter.default_panel')))`.
- **`PanelTableResolver`** (`final readonly`): `table($panel,$base)=registry->get($panel)->table($base)` — тонкий шов, чтобы миграции/модели не держали Panel. Фасад `Vaulter`: `panel($name='default')`, `panels()`, `registerPanel()`.
- **`IdStrategy`** (`src/Schema/`) — контракт 5 методов (`key/reference/actor/cast/modelConcern`) + `AutoIncrementIds/UlidIds/Uuid7Ids` + `IdStrategyResolver` (instantiate из контейнера, дефолт `config('vaulter.ids.default')`, бросает на неизвестную). На панель: `PanelTablesBuilder::ids()` зовёт `resolve($panel->idStrategyKey())`.
- **`PanelTablesBuilder`** (`src/Schema/`) — **одно определение колонок** для shipped `v_*` и сгенерированных per-panel миграций (без дрейфа). `createAll(Panel)/dropAll(Panel)` (генерированные); `createTable(Panel,entity)/dropTable(Panel,entity)` (базовые one-per-file, `match` по entity). Фиче-флаги гейтят таблицы: `storages`/`nodes` всегда; `files/file_versions/blobs` от `versions`/`blobs`; `node_grants` от `grants`; `node_shares` от `share_links`; `upload_sessions` от `uploads`; `document_versions` от `document_versions`; `activity` от `activity`. Условные колонки: `panelDiscriminator()` (только `storages`+`nodes`), `extraData()` (по конфигу). Каждый `define*` гонит типы через `IdStrategy` + явный `$t->foreign(...)->on($panel->table('nodes'))->cascadeOnDelete()` (FK на СВОИ таблицы панели). **Единственный файл с реальной доменной схемой** = `PanelTablesBuilder::define*` (`defineStorages/defineNodes/defineFiles/defineFileVersions/defineBlobs/defineDocumentVersions/defineNodeGrants/defineNodeShares/defineUploadSessions/defineActivity`).
- **`VaulterSchema`** (`src/Support/`) — статический хелпер для рукописных миграций (`key/reference/actor/morphs/tenant/panel/extraData/bits`), global-стратегия; `PanelTablesBuilder` — per-panel.

### 4.3 Генераторы (поток make-panel / panel:migrations)
- **`vaulter:make-panel {name} {--host=} {--prefix=} {--no-grants} {--id=} {--force}`** (`MakePanelCommand`): snake/studly → prefix (дефолт `{id}_drive`) → entities из `ENTITIES` (`Storage/Node/File/FileVersion/Blob/Grant/Share`; убирает `grants` при `--no-grants`) → **writeModels** (по одной `App\Vaulter\Models\{Studly}{Suffix}` из `panel-model.stub`) → **writeProvider** (`App\Vaulter\Panels\{Studly}StoragePanel`) → **printNextSteps** (1. зарегистрировать FQCN в `config('vaulter.panels')`; 2. править флаги/хуки; 3. `vaulter:panel:migrations`; 4. свои колонки — своей миграцией). Намеренно НЕ трогает конфиг и не эмитит миграции.
- **`vaulter:panel:migrations {name} {--force}`** (`PanelMigrationsCommand`): резолв панели → `$generator->generate(panel, force)`.
- **`PanelMigrationGenerator`** (`final readonly`): `generate(Panel,force,offset)` — **отвергает `!hasOwnTables()`** (дефолт-стилю файл не нужен); prefix явный или `Str::beforeLast($panel->table('storages'),'_storages')`; имя `create_{prefix}_drive_tables`; `migrationExists()` игнорит timestamp; offset держит batch уникальным; стаб резолвит панель из реестра **в момент миграции** и делегирует билдеру (не дрейфует): `up(){ (new PanelTablesBuilder)->createAll(app(PanelRegistry::class)->get('{{ panel }}')); }`.
- **Эволюция схемы** — НЕ регенерация, а версионные **delta-миграции с фан-аутом по реестру** (`Schema::table` по всем префиксам, guard `hasColumn`, список панелей заморожен в дельте). Команда `vaulter:panel-feature <name> <feature>` — включить фичу позже. Генерация timestamped-файла — через реимплементацию `MigrationCreator` (НЕ `GeneratorCommand` — не умеет timestamp).
- **Concerns:** `HasVaulterKey` (config-driven PK, не `HasUlids`; ставит string/non-incrementing для ulid/uuid7, заполняет ключ на `creating`, seam `vaulterIdStrategyKey()`); `HasVaulterPanel` (`getTable()`→`vaulterPanel()->table($entity)` напр. `project_drive_nodes`; `vaulterIdStrategyKey()`→стратегия панели — чтобы очереди/broadcast/Scout регидрировали в правильную таблицу); `HasExtraData` (`extra_data`→`AsArrayObject`).

### 4.4 Runtime-выигрыш
`EloquentNodeRepository::query(?string $panel)` резолвит `panels->get($panel ?? 'default')->model('node')` + `app($class)` → каждое чтение/запись в схему панели. `fillNode()` добавляет panel-атрибуты (включая `panel`); `presentNode()`→`NodeResource::withPanelExtras()` мёржит под `extra`. Ядро остаётся panel-agnostic.

### 4.5 Расширяемость — реестры, хуки, swappable-всё (требование владельца)

Всё новое встраивается **без правок ядра и без миграций**. Точки расширения:

**1. Новые форматы документов — handler-registry (без миграции).** `Vaulter\Contracts\DocumentHandler` (`id()`, `mimeTypes()`, `extensions()`, `priority()`, `capabilities(FileDescriptor): DocumentCapabilities{editable|previewable|downloadable|thumbnailable|collaborative}`, `ui(FileDescriptor): HandlerUi{vue-компонент + route}`). Регистрация: tagged-биндинг контейнера `vaulter.handlers` + `config('vaulter.handlers')` + рантайм `Vaulter::registerHandler(MyCadHandler::class)`. Хочешь редактировать новый формат (CAD, новый табличный, что угодно) → пишешь один handler-класс, регистрируешь, метаданные формата — в `extra_data jsonb`. Резолв: mime-first → extension-fallback → capability-filter → priority → null-object `DownloadOnlyHandler`. **Ноль изменений ядра, ноль миграций.**

**2. Хуки на панель** (override-методы провайдера, no-op по умолчанию): `fillNode(StoreNodeData): array` (доп. атрибуты на запись), `presentNode(Node): array` (доп. атрибуты в API/Resource), `pipes(): array` (доп. шаги ingest/move), `booted(Panel): void`.

**3. Pipeline-pipes** (`config('vaulter.handlers.*')`): вставка шагов до/после `CommitVersion`/`RepointHead` без патчей ядра (антивирус, OCR, водяной знак, извлечение текста, уведомления) — глобально / на тип / на панель. Любое число.

**4. Swappable через config (FQCN, cache-safe):** любая модель (`models.*`), драйвер прав (`database|azgard|spatie|gate` + свой), storage-драйвер (Flysystem + свой), collaboration-драйвер, exporter (`exporters` — новый формат экспорта = класс), token-generator, **id-стратегия** (`ids.strategies` — напр. Snowflake = свой класс `IdStrategy`).

**5. Реестры:** `DocumentHandlerRegistry`, `PanelRegistry`, `StorageTypeRegistry` (пресеты политики private/team/public), `ExporterRegistry` — все наполняются из config + рантайм-`register*()`.

**6. События парами intent/fact** (`NodeMoving/Moved`, `VersionCommitting/Committed`, `ShareCreating/Created`, …) — слушатели/вебхуки/внешние интеграции без зависимости ядра от них.

**7. `extra_data jsonb`** на каждой доменной таблице — произвольные поля потребителя без миграций (escape-hatch). Запрошенные/сортируемые/FK поля → реальная колонка отдельной follow-up миграцией; sparse/experimental → `extra_data`.

**8. Типизация:** enum/VO (`NodeKind`, `Permission` bitmask, `GrantLevel`, `DocumentCapabilities`, `FileDescriptor`, `NodeRef`), DTO на вход (spatie/laravel-data), типизированный `Support\Config`, Pest Arch-тесты стерегут границы и FQCN-only конфиг.

---

## Раздел 5 — Структура пакетов (слои + контракты)

**`vaulter-core` (`Vaulter\`):** `Contracts/` (IdStrategy, PermissionResolver, StorageDriver, CollaborationDriver, ShareTokenGenerator, NodeRepository, BlobRepository, Exporter) · `Data/` (StoreNodeData, MoveNodeData, GrantData, ShareData, UploadInitData) · `Drivers/{Permission/{Database,Azgard,Spatie,Gate}, Storage/Flysystem, Collaboration/Hocuspocus}` · `Enums/` (NodeKind, GrantEffect, GrantLevel, StorageType, ShareScope, UploadStatus, BlobStatus, Permission(bitmask)) · `Events/` (NodeCreated/Moved/Trashed/Restored, VersionCommitted, GrantChanged, ShareCreated, BlobOrphaned) · `Exceptions/` (UnknownPanel, QuotaExceeded, NodeNameConflict, InvalidGrant) · `Facades/Vaulter` · `Http/{Controllers/{Node,Upload,Share,Download}, Resources/{Node,Storage,Version}, Requests, Middleware/ResolveShareToken}` · `Models/` (+`Concerns/{HasVaulterKey, HasVaulterPanel, HasExtraData, InteractsWithWorkspace}`) · `Panels/` · `Pipeline/` (IngestFile/CreateNode/MoveNode + Pipes/{ValidateUpload, AssembleBlob, DedupBlob, CommitVersion, RepointHead, RecordActivity}) · `Policies/NodePolicy` (делегирует PermissionResolver) · `Repositories/` (Contracts + Eloquent/{Node,Blob,Grant}) · `Schema/` (IdStrategy+3+Resolver+PanelTablesBuilder) · `Services/` (NodeService, GrantService, ShareService, TrashService, ActivityRecorder, OutboxDispatcher, TreeService) · `Support/` (Config, VaulterSchema, RandomShareTokenGenerator, PathResolver, EtagPropagator) · `Console/Commands/{Install, MakePanel, PanelMigrations, GcBlobs, PurgeTrash, SweepUploads}` · `VaulterServiceProvider`. Стабы `stubs/panel/*`. Базовые миграции — тонкие оболочки →`createTable`.

**`vaulter-media` (`Vaulter\Media\`, STANDALONE):** `Contracts/{ContentStore, ChunkStore}`, `Cas/{Sha256ContentStore, BlobGarbageCollector, RefCounter}`, `Upload/{UploadSessionManager, MultipartAssembler, TusHandler}`, `Acl/PerFileAclResolver`, `Models/{Blob, UploadSession, UploadPart}`, `Services/MediaService`, `Support/ContentHasher`, `Console/GcBlobs`, `VaulterMediaServiceProvider`.

**`vaulter-drive` (`Vaulter\Drive\`):** `Actions/{CreateFolder, UploadFile, MoveNode, CopyNode, RenameNode, TrashNode, RestoreNode, StarNode}`, `Http/Controllers/DriveController`, `Services/DriveService`, провайдер.

**`vaulter-docdrive` (`Vaulter\DocDrive\`):** `Contracts/CollaborationDriver`, `Drivers/HocuspocusDriver`, `Models/DocumentVersion`, `Services/{DocumentService, YjsStateService, SnapshotService}`, `Export/{TiptapToHtml, TiptapToMarkdown}`, `Http/Controllers/{Document, Collaboration}`, провайдер. + **handler-registry** (по mime/расширению → editable\|previewable\|download-only): встроенные TipTap/Preview/DownloadOnly + фичефлажный Univer(XLSX, в v1 single-user) + опц. OnlyOffice/Collabora (BYO-лицензия).

**`@vaulter/client`/`@vaulter/vue`:** client (typed REST, types, errors) · vue (components/{VaulterDrive, NodeTree, FileGrid, DocEditor}, composables/{useVaulterClient, useUpload, useCollaboration}).

**Ключевые контракты:** `PermissionResolver::resolve(Authenticatable, Node, Permission):bool` + `effectiveGrants(subject, node):GrantSet`; `StorageDriver::put/get/delete/url(Blob)`; `CollaborationDriver::connect/snapshot(DocumentVersion)`; `ShareTokenGenerator::generate():string` (plaintext один раз); `Exporter::export(Node):Response`.

---

## Раздел 6 — Права и tenancy

- **100% делегирование хосту** (ADR-0002). Контракт расщеплён на `HostRoleDriver` (сменный, на панель) + Vaulter-owned `AccessResolver` (см. H.2). Нет драйвера прав = **fail-loud в проде** (open `NullRoleDriver` — только non-prod). Дефолт-драйвер — **Database** (standalone из коробки); FlexCRM/CoreX берут **Azgard**.
- **AzgardResolver** (`vaulter-permissions-azgard` или внутри media/core drivers): мапит действие→Azgard-permission enum, зовёт `hasScopedPermission($perm, $node)`; `canCreate`→`hasPermissionIn($workspaceType, $workspaceId, 'documents.create')`. Композитный порядок: **node-ACL → workspace-scoped роль → глобальная permission**. Реюз Corex `TenantContextGuard` (`corex/auth/src/Tenancy/TenantContextGuard.php`) как шаблон (workspaceType→`EntityRegistry::classForAlias`→`whereKey`→`hasScopedPermission`). Требует выравнивания `vaulter.ids.default=ulid` + `corex.ids.strategy=Ulid` + `AZ_GUARD_MORPH_TYPE=ulid` и регистрации `workspace_type`/`owner_type` как морф-алиасов Corex. **Закрываем дыру:** сегодня FlexCRM на плейсхолдере `App\Vaulter\AllowAuthenticatedResolver` — пишем реальный драйвер + Pest-тесты.
- **Tenancy host-owned:** `VaulterSchema::tenant()` добавляет `tenant_id` всем таблицам; ядро не скоупит. Хост подменяет модель на подкласс с Corex `BelongsToTenant`+`TenantScope`. Граница тенанта в FlexCRM = `Workspace implements CoreX\Contracts\Tenant`.

---

## Раздел 7 — DocDrive (обработка документов)

Handler-registry по mime/расширению (тот же идиом, что `CollaborationDriver`/`exporters`): флаги `editable\|previewable\|downloadable\|thumbnailable\|collaborative`. Уровни: (1) рич-текст — **TipTap+Yjs/Hocuspocus** (есть). **Yjs-бинарь = источник истины; TipTap JSON = производная проекция** (пишется в `onStoreDocument`, debounce). Бизнес-версии `v_document_versions` (прунинг `max`, `keep_forever`) отдельно от Yjs-внутренностей. (2) XLSX — **Univer** (Apache-2.0 SDK) как `UniverHandler`, v1 single-user (collab/импорт-экспорт Univer — Pro/отдельный OT-движок, не Yjs). (3) высокая OOXML-точность — опц. OnlyOffice Developer / Collabora (WOPI), включает потребитель (BYO-лицензия). (4) PDF/изображения/архивы — preview/download null-object. **Декаплинг:** docdrive потребляет узлы через `FileNodeResolver`/стрим, не видит путей диска (arch-тест против `Storage::`/`*::path()`). **Hocuspocus:** доделать `onAuthenticate` (JWT vs Laravel webhook) + `onStoreDocument` (снапшот→`v_document_versions`) + `ReverbCollaborationDriver::issueToken`; дистрибуция — образ `ghcr.io/axioma-studio/vaulter-hocuspocus` + compose под профилем `realtime`.

---

## Раздел 8 — Документация (поток работ)

Текущая `docs/` (`docs/guide/*`, ADR 0001–0004, `docs/ru/*`) описывает **старую DocVault-концепцию** и устарела — это материал к ревизии, не источник истины. Дисциплина — как у chatom.

- **ADR.** Формат сохраняем. Старые помечаем `Status: Superseded by …` (напр. 0003 embeddability → эволюционирует в Storage Panels). Новые решения ре-платформинга — **ADR 0005+**: 0005 единое дерево узлов (adjacency+CTE) · 0006 CAS-блобы + ref-count GC · 0007 драйверы прав + ACL-наследование · 0008 решение по Media Library (custom vs spatie) · 0009 Storage Panels · 0010 PHP+npm-монорепо + split/changesets · 0011 id-strategy (ulid-дефолт) · 0012 dedup-scope.
- **`docs/plan/`.** Этот план кладётся в `docs/plan/{00-overview,01-philosophy,02-database,03-monorepo,04-panels-config,05-packages,06-permissions,07-docdrive,08-roadmap}.md` — навигация исполнителям; **удаляется** после реализации (мигрирует в финальный VitePress).
- **Доки обновляются В КАЖДОЙ ФАЗЕ** (не только в конце). Каждая фаза доставляет свой срез: гайды Core/Media/Drive/DocDrive/Vue/Filament, отдельный гайд **«Storage Panels»** (make-panel/panel:migrations), **«Database schema»** (поколоночно), API-референс, страница per-file ACL/sharing.
- **Финальная фаза консолидации:** переписать VitePress-гайд под новую архитектуру (Get Started / Installation / Guides per package / Storage Panels / API), заменить устаревшие страницы, удалить `docs/plan` и legacy-черновик, опубликовать сайт двуязычно (`docs/ru`). Зеркалировать структуру chatom (после реализации `docs/plan/` и `docs/draft/` удаляются).

---

## Раздел 9 — Дорожная карта (Drive-first; доки в каждой фазе)

**M0 — Фундамент + порт (нед. 1–2).** Перенести `Schema/*` (IdStrategy+3+resolver) и `Panels/*` (+concerns, стабы, команды) из chatom; расширить `VaulterSchema` (`reference()` с реальным FK+cascade, `extraData()`, `bits()`, `panel()`); расширить `HasVaulterKey` (ULID-only → `IdStrategy`); конфиг (`ids` принимает uuid7, `panels`, `default_panel`, `migrations.path`, `models.*`, `features`, `handlers`); фасад `Vaulter::for($entity, panel:)`; **исправить CI `@docvault`→`@vaulter`**, добавить `.changeset/`+`release-npm.yml`, split-репо+`monorepo-split.yml`. ADR 0005/0009/0010/0011/D21-расширяемость. **Доки:** `docs/plan/*` + гайд «Storage Panels» (черновик, с акцентом на zero-migration). *Готово:* `vaulter:make-panel` создаёт **конфиг-панель без миграций**; дефолт-схема `v_storages`+`v_nodes` обслуживает любые сущности через полиморфный owner; `vaulter:panel:migrations` работает как **опц.** режим для панели с `tablePrefix`; зелёный CI; первый `@vaulter/vue` + split `vaulter-core`.

**M1 — Хребет Drive (нед. 3–5).** Миграции+модели `v_storages/v_nodes/v_blobs/v_files/v_file_versions/v_file_version_blobs`; `PanelTablesBuilder::define*` для всех шести; partial-uniq сиблингов (PG)+generated-колонка (MySQL). `vaulter-media`: `Sha256ContentStore` поверх Flysystem, `RefCounter`, ingest-pipeline (Validate→Assemble→Dedup→Commit→Repoint), GC-команда. `vaulter-drive`: CreateFolder/UploadFile/MoveNode/RenameNode, adjacency-дерево (staudenmeir), листинг+breadcrumb. ADR 0006. **Доки:** гайды Media/Drive + «Database schema» (storages/nodes/blobs/files). *Готово:* upload→dedup→version→download; move/rename одним UPDATE; GC-джоб; учёт квот; **standalone-демо `vaulter-media`**.

**M2 — ACL/шеры/корзина/activity (нед. 6–8).** `v_node_grants`+CTE-наследование+`PermissionResolver` Database-драйвер+`NodePolicy`; `v_node_shares`+ResolveShareToken+публичное скачивание; trash-колонки+PurgeTrash; `v_activity` (одна строка, получатели на чтении)+ActivityRecorder; драйверы Azgard/Spatie/Gate (+Pest-тесты Azgard). ADR 0007/0008/0012. **Доки:** гайды Permissions/Sharing/Trash, per-file ACL. *Готово:* grant/deny+наследование+cut-off; «доступные мне»; шер-ссылки; 30-дн корзина; лента.

**M3 — Резюмируемые загрузки + надёжность (нед. 9–10).** `v_upload_sessions`+`v_upload_parts`; S3-multipart+tus.io; SweepUploads; порт `v_outbox`+`v_webhook_*` 1:1; OutboxDispatcher+webhook-доставка с backoff/HMAC/DLQ. **Доки:** Uploads + Webhooks/Outbox. *Готово:* резюмируемая загрузка мульти-ГБ; подписанные вебхуки на node/share/version.

**M4 — DocDrive (нед. 11–13).** `v_document_versions`(+`yjs_state`); document-узлы; TipTap-снапшот+автосейв+именованные версии+прунинг; handler-registry; `CollaborationDriver`+Hocuspocus (доделать стабы)+Reverb issueToken; `@vaulter/vue` DocEditor+`useCollaboration`; экспорт (Tiptap→HTML/MD). ADR (collab). **Доки:** DocDrive + Collaboration + Hocuspocus-деплой. *Готово:* совместное редактирование в дереве; история версий; экспорт.

**M5 — Filament + мост + read-models + консолидация доков (нед. 14–16).** `vaulter-filament` (Storage/Node/Grant/Share/Activity); `vaulter-medialibrary-bridge` (импорт spatie media→nodes/blobs); опц. ltree/closure read-model + EtagPropagator + ленивый rollup размера папок. **Финальная консолидация документации** (§8): переписать VitePress, заменить устаревшее, удалить `docs/plan`+legacy, опубликовать сайт. **Domain-restructure+typed-config pass** (если решим доменные папки, как RFC chatom G1) — один механический проход под сетью тестов. *Готово:* Filament-админка; medialibrary-импорт; opt-in scale read-models; **актуальная VitePress-документация**.

---

## Раздел 10 — Риски и митигации
- **Big-bang** → strangler (`v_nodes` рядом с `v_documents`, бэкфилл, cut-over).
- **Дрейф ref_count/GC** → связи/рёбра = истина, ref_count = кэш, `reconcile`+two-phase delete с grace, не доверять клиентскому sha256/S3 ETag.
- **Мульти-тенант дедуп-утечка** → дефолт per-tenant, global — opt-in.
- **Портативность/перф CTE (MariaDB)** → `withMaxDepth()`, индекс `(parent_id,name)`, кэш, ltree/closure только при масштабе.
- **Уникальность сиблингов vs soft-delete** → partial-uniq `WHERE deleted_at IS NULL AND trashed_at IS NULL` (PG) / generated-колонка (MySQL).
- **Слияние двух каналов шеринга** («есть ссылка» ≠ «я в команде») → bearer отдельным проходом ∩ item-safety.
- **Move/trash поддерева без транзакций/цикл-гарда** → лок source+dest, запрет переноса под потомка, атомарный bulk.
- **Footgun «модель-хамелеон»** (runtime-`$table` ломает serialize→rehydrate в очередях/broadcast/Scout) → **конкретный класс-модель на панель** (model-map), не shared с runtime-`$table` (вердикт chatom F3).
- **STI sparse mega-row** → нагрузка в 1:1 side-таблицах (`v_file_versions/v_document_versions`); на `v_nodes` — только identity/дерево/кэш.
- **Azgard не покрыт тестами; `workspace_type` без морф-алиас-контракта** → M2: реальный драйвер+Pest+морф-алиас-гард+явный порядок резолва.
- **Преждевременное дробление** → `vaulter-media` оправдан (standalone+ACL, второй потребитель = другие проекты владельца); остальное мельче — только за опц. тяжёлой зависимостью. media спроектирован схлопнуться в drive (граница на контрактах core).
- **Config-flag honesty** (вердикт chatom F4): инфра-фичи (collab/search/retention) = driver-биндинги с fail-fast, НЕ фейковый bool; реальные флаги — только дешёвый sync-путь.
- **Две realtime-системы (Univer-collab)** → v1 Univer single-user; Pro-collab — opt-in потребителя.
- **Лицензии OnlyOffice/Collabora** → только BYO опц.-handlers, не дефолт-deps.
- **Устаревшая документация** (D20) → перепись в каждой фазе + финальная консолидация; legacy-ADR помечаются Superseded.

---

## Раздел 11 — Открытые вопросы (дефолчу, поправь)
1. **`@vaulter/client`** — выносить сейчас или держать в `@vaulter/vue` до React/Svelte-адаптера? (рек.: держать в vue).
2. **Дробление media/drive/docdrive** — Option A (свернуть в core, дробить позже) vs Option B (с первого дня)? (рек.: media — standalone сразу (D3); drive/docdrive — выносить когда швы заболят).
3. **Ось `storageType`** — нужны ли пресеты политики (private/team/public), или хватает только оси панели? (рек.: оставить лёгкий `storageType`, можно выпилить).
4. **Deny-ACE** — оставить enterprise `allow\|deny` (дефолт), или чистый Drive allow-only + deny за флагом?
5. **Дефолт PK** — подтвердить `ulid` (D9; Corex/Azgard alignment); uuid7 — для native PG `uuid`. Выбор на панель всегда.
6. **Document-блобы** — документы минуют блобы (inline jsonb), или большие вложения в доках через `v_blobs`?
7. **Чанкинг** — старт fixed 4 MiB (Dropbox), CDC (Seafile) отложить?
8. **Rollup размера папок** — лениво `-1`+фон, или считать on-demand для мелких деревьев?
9. **Tag-схема** — PHP-поток `vaulter-php-vX.Y.Z`, или продуктовый lockstep `vX.Y.Z` для PHP+npm?
10. **Lock-файлы** — коммитить `package-lock.json` (для `npm ci`) и/или `composer.lock`? (сейчас оба ignored).
11. **Доменные папки** (RFC chatom G1) — переходить ли на доменную структуру (`Nodes/`, `Files/`, `Grants/`…) и когда (один проход в конце под сетью тестов)?

---

## Раздел 12 — Verification
- **Схема/миграции:** golden-migration тесты гоняют весь набор `v_*` под **3 id-стратегиями** (bigint/ulid/uuid7) × **2 СУБД** (PG+MySQL) через Testbench; partial-uniq (PG) и generated-колонка (MySQL) отвергают дубль живого имени, но допускают trashed-дубль; `extraData()/tenant()/panel()` эмитят/пропускают колонки по конфигу.
- **Панели:** в тесте `vaulter:make-panel demo --host=App\Models\Project --prefix=demo_drive`, регистрация FQCN, `vaulter:panel:migrations` → `demo_drive_storages`/`demo_drive_nodes` со стратегией панели; `HasVaulterPanel::getTable()` резолвит префикс; queued-job регидрирует в правильную схему; `PanelMigrationGenerator` отвергает no-own-tables; стаб резолвит из реестра в момент миграции (без дрейфа); изоляция: узлы панели A не текут в таблицы B/default.
- **Дерево:** property-тесты — move/rename = один UPDATE без записей в потомков; `descendants()/ancestors()` CTE vs brute-force; `withMaxDepth` режет в движке; цикл-гард на move-в-своё-поддерево.
- **CAS/дедуп/GC:** дважды одинаковые байты → один блоб, ref_count=2, без второй физ. записи; удалить одну версию → ref_count=1, байты целы; обе → two-phase GC, tombstone идемпотентен; dedup_scope (global/tenant/storage) уважается ключом.
- **ACL:** табличные тесты наследования — direct vs inherited, cut-off `inherit_grants=false`, `deny`-бьёт-`allow` ближе, OR bitmask, expiry; контракт-тест: каждый драйвер (database/azgard/spatie/gate) даёт одинаковое решение на общей фикстуре.
- **Загрузки:** сессия переживает разрыв (offset `received_bytes`); S3 part re-upload заменяет; assemble→sha256→dedup; sweep протухших по `(status, expires_at)`.
- **Надёжность:** outbox дренит один раз (`event_id` uniq); webhook backoff по `(status, next_retry_at)`; подпись проверяется.
- **Качество (скиллы/CI):** Pint+PHPStan/Larastan (baseline), Pest Arch (границы слоёв, нет кросс-пакетных утечек, FQCN-only конфиг, каждая модель `HasVaulterKey`+`HasExtraData`, каждая таблица через `VaulterSchema`/`PanelTablesBuilder`), Infection MSI-гейт на core Services/Schema/Panels, Playwright E2E Vue-диска (upload/move/share/trash), CI-матрица по путям, `composer validate` на split-пакет.
- **e2e ручная:** demo-app — создать storage → папки (на диске НЕ появляются) → загрузить PDF/изображение/DocX → DocX открывается редактором, PDF — превью, архив — download; выдать доступ user/role; корзина→restore; **standalone `vaulter-media`** в чистом Laravel: прикрепить файлы с per-file ACL без Drive.
- **Интеграция FlexCRM:** заменить `AllowAuthenticatedResolver` на `AzgardResolver`; задача создаёт storage, к ней цепляются узлы; доступ через Workspace=tenant; зелёные тесты FlexCRM.

---

# Финализация дизайна — свод hardening-правок (приоритетнее ранних формулировок)

> Выверено: 5 слабых мест × (deep research + adversarial-верификация) + синтез против кода. **При конфликте этот свод приоритетнее §1–§12.** Источники: AWS S3 / NTFS [MS-DTYP] (ACL), PostgreSQL advisory-locks + `INSERT…ON CONFLICT` docs (CAS), staudenmeir Known Issues / MDEV-19077 (CTE), OpenFGA CVE-2026-48096 (cache-key), Filament 5 / Laravel 12 / spatie-permission v8 (API). Полные досье — в памяти проекта.

## H.0 Что в плане было НЕВЕРНО/рискованно (исправлено ниже)
1. **`v_blobs.storage_key` — латентная потеря данных.** Scope-free шард `ab/cd/<sha256>` + per-scope дедуп (`unique(tenant_id,sha256)`) ⇒ два scope делят один физ. объект, но две строки; GC одного scope удаляет живые байты другого. → **H.3.**
2. **GC назван «race-free» без полного аргумента.** Advisory-локи non-MVCC. → инвариант lock-first (**H.3**).
3. **Синхронный `ref_count++` в commit** — hot-row на популярном блобе. Убрать, считать реконсиляцией (**H.3**).
4. **ACL §2.6** — нет deny-вето после `max` и нет границы по workspace. → **H.2**.
5. **Нет драйвера прав = open в проде** (`DocumentVisibilityService` → `true`). → fail-loud в prod (**H.2**).
6. **Spatie — устаревшая версия** (v6). Канон — **v8** + `forgetCachedPermissions()` (**H.2**).
7. **`delete` без `GrantLevel`-кейса** — схлопнуть в `organizer`/Manage (**H.2**).
8. **`DocumentType`/`NodeKind` enum на границе Action ≠ runtime-регистрация форматов** — реестр (string-ключи) = источник истины, enum = сахар (**H.5**).
9. **Per-panel `idStrategy` несвязен на общей схеме** (одна `v_nodes` = один тип PK). Разрешать только в own-tables режиме D12 (**H.1**).
10. **`:`-склейка ключей ACL-кэша = форма CVE-2026-48096** — version-stamped + length-prefixed/hashed (**H.4**).
11. **`whereHas/withCount('descendants')` фатален на MariaDB** — arch-бан в request-путях (**H.4**).
12. **Одиночная мемоизация в `VaulterManager`** (`permissionResolver`/exporters) обслуживает первую панель навсегда — ключевать по panel-id (**H.1**).

## H.1 Per-panel конфиг над общей схемой
- **Per-panel resolved-state — ключевать по panel-id** в `VaulterManager`/реестрах (никакой одиночной мемоизации).
- **FQCN-валидация на boot:** `StoragePanelProvider::resolve()`/`PanelRegistry::fromConfig()` для каждого FQCN (driver/model/pipes/handlers/idStrategy) — `class_exists()` + `is_a(<Contract>)` ⇒ `InvalidPanelException` на boot, не в рантайме.
- **Контекст панели переживает очередь/broadcast/Scout ПО ЗНАЧЕНИЮ:** concern `WithPanelContext` сериализует `panel` (строка) рядом с `tenant_id`, восстанавливает контекст **до `handle()`**, композится с tenant-контекстом (tenant → потом panel). Никогда не сериализовать объект панели.
- **Middleware-порядок:** резолвер контекста панели/workspace/share-токена — **до `SubstituteBindings`** (через `bootstrap/app.php` `->addToMiddlewarePriorityBefore(SubstituteBindings::class, …)`, API Laravel 11.27+/12; НЕ легаси `$middlewarePriority`). Иначе route-model-bound `Node` под null-панелью путает 404↔403.
- **`scoped()` — только HTTP.** В очередях/CLI/Octane `scoped()` не флашится per-call ⇒ контекст панели/workspace передавать **явно через Action-DTO** (`StoreNodeData`).
- **Per-panel `idStrategy`** разрешён **только** в own-tables режиме (D12); на общей схеме `PanelRegistry` его отвергает.
- **CI-гейт:** Pest-arch — `config:cache` (`var_export(config('vaulter'))` не бросает) ⇒ ни одной closure в `panels/handlers/exporters/drivers`.

## H.2 Гибридный ACL + сменные ролевые драйверы (D6′/D7′/D6″/D22)
- **Разделить контракт на два:**
  - `HostRoleDriver` (сменный, на панель; identity-agnostic, НЕ течёт `Node`/magic-string): `resolve(Subject, Authorizable, AclScope): ?Decision`, `override(Authorizable, AclScope): ?Effect` (super-admin/`Gate::before`), `subjectsFor(Authorizable): list<Subject>` (actor → user+роли+команды, чтобы Vaulter сам строил `WHERE subject IN (...)`), `canCreateInWorkspace(Authorizable, type, id): bool`.
  - `AccessResolver` (Vaulter-owned, **НЕ сменный**) — гоняет алгоритм эффективных прав над `v_node_grants` + `HostRoleDriver`.
  - VO: `Subject(type,id)` (морф-алиас), `AclScope(panel, workspaceType, workspaceId, ?nodeOwner)`, `enum Effect{Allow,Deny}`, `Decision(GrantLevel, Effect)`.
- **Алгоритм эффективных прав (deny-wins, explicit-beats-inherited):**
  1. **Host override** (super-admin) — абсолютно, в обе стороны.
  2. Идти по предкам **ближайший-первым**, **в границах workspace-корня** (никогда не пересекать `storage_id`/`workspace_*`), собирая `direct` + `scope=subtree` для subject-set; **стоп на первом `inherit_grants=false`**.
  3. `aclAllow = max(allow-levels)`; `deniedBits` (deny на узле запрещает уровень и всё, что он имплицирует); ближний explicit-allow пробивает дальний inherited-deny; deny на `inherit_grants=false`-узле — неперекрываемая стена.
  4. `granted = max(aclAllow, roleAllow)` из `HostRoleDriver`.
  5. **Финальное вето:** `if deniedBits ⊇ requested → DENY` (роль хоста не может воскресить запрещённый уровень).
- **Expiry — на чтении** (фильтр `expires_at` в запросе/алгоритме, не крон; в ключ кэша — time-bucket/короткий TTL). **«Доступные мне»** — быстрый проход по `(subject_type,subject_id)`, затем **переоценка каждой строки** резолвером (ближний deny мог отозвать). **`delete` схлопывается в `organizer`/Manage** (нет stringly-typed action). **`subject_type` — только зарегистрированный морф-алиас** (Pest-тест отвергает неалиасованные).
- **`SpatiePermissionResolver`** инкапсулирует весь team-switch **внутри драйвера**: `setPermissionsTeamId($scope->workspaceId)` + `$actor->unsetRelation('roles')->unsetRelation('permissions')` + `forgetCachedPermissions()` на записи (spatie **v8**). Не течёт в ядро.
- **Нет драйвера = fail-loud в проде:** prod-валидация конфига отвергает панель/workspace без `HostRoleDriver`; `NullRoleDriver` (open) — только non-prod.

## H.3 CAS-блобы: дедуп + ref-count + гонко-безопасный GC (D18′/D-GC)
- **`storage_key` scope-квалифицирован:** `<scope>/ab/cd/<sha256>`, где `scope` = значение dedup-scope (`tenant_id`/`storage_id`/`'global'`). Иначе per-scope дедуп + scope-free путь = кросс-scope удаление. Альтернатива — гард «нет другой строки `v_blobs` (любой scope) на этот `storage_key`» перед физ. удалением.
- **Инвариант безопасности (не «provably race-free», а явное правило):** каждая транзакция, меняющая liveness `(scope, sha256)` — upload-commit, GC-mark, GC-sweep — **первым стейтментом** берёт `pg_advisory_xact_lock(hash(scope||sha256))` и **все** решения о liveness принимает из строк, прочитанных **после** взятия лока (`SELECT … FOR UPDATE`). SWEEP дополнительно `SERIALIZABLE` + retry-on-40001.
- **Commit-flow (одна транзакция):** хешировать **локальный staged temp-файл** (seekable), не live-поток; на S3 слать `x-amz-checksum-sha256` (серверная сверка staged-байт), ETag — только debug. Получение id блоба — **НЕ** `ON CONFLICT DO NOTHING … RETURNING` (на конфликте 0 строк → NULL-id); ветка advisory-lock + `SELECT … FOR UPDATE` затем INSERT, либо `ON CONFLICT (scope,sha256) DO UPDATE SET sha256=EXCLUDED.sha256 RETURNING id`. Промоут staged→CAS-ключ с **`If-None-Match: *`**; `412` **и** `409` = «нас обогнали → пере-SELECT и reuse», не фатал. **CAS-бакет unversioned**; S3 lifecycle только `AbortIncompleteMultipartUpload` + протухание `tmp/` — **никогда** как механизм удаления блобов.
- **Убрать синхронный `ref_count++`** из commit (hot-row); `ref_count` строится реконсиляцией; **истина liveness = существование ребра** в `v_file_version_blobs`.
- **Состояния блоба + воскрешение:** `PENDING → COMMITTED → TOMBSTONED → (deleting) → DELETED`; конкурентная загрузка, нашедшая `TOMBSTONED`, **разтомбствляет** его в той же locked-транзакции (это и делает online-GC безопасным). SWEEP берёт только `state=TOMBSTONED AND delete_after_at<now()`, перепроверяя **под локом**. Отдельный sweep `PENDING` (упавший staging).
- **GC ACL-blind** (liveness ≠ права смотрящего; узел, который юзер не видит, всё равно пинит блоб); GC/реконсиляция — как system без panel/workspace. **`dedup_scope ⊆ scope ключа шифрования** (plaintext-sha256 дедуп не пересекает границу шифрования). Grace `gc_grace` ≥ макс. латентности multipart-commit (дефолт 24h, пол 1h, на панель). Алерт на осцилляцию `TOMBSTONED↔COMMITTED` и на хиты реконсиляции (orphan-объекты/висящие строки).

## H.4 Дерево + горячий путь наследования (D5′/D-CACHE)
- **ltree/closure — opt-in драйвер, НИКОГДА не дефолт.** На PG — `ltree`; иначе — delimited-varchar с **разделителями с обоих концов** (`.id.`, чтобы `1.2` не матчил `1.20`); путь по **id, не имени** (rename = 1 UPDATE; только move переписывает пути потомков). **ULID-метки** ltree не держит свободно ⇒ безопасное кодирование или **closure-fallback на ULID-хостах**.
- **Инвалидация — бампы версий через observers/events, НЕ DB-триггеры** (триггеры каскадят безгранично на move/bulk-grant): grant → `node.acl_version++`; move → один set-based `UPDATE` бампит `tree_version` по поддереву (async при огромном, короткое окно eventual-consistency); смена роли/команды субъекта → `subject.subject_version++`; смена role-map драйвера → глобальный `model_version++`. На каждой записи кэша — backstop-TTL 30–60s.
- **Ключ ACL-кэша (когда добавим): version-stamped со ВСЕМИ входами** (`subject_id`,`node_id`,`acl_version`,`tree_version`,`subject_version`,`model_version`) и **length-prefixed/hashed**, не наивная `:`-склейка (OpenFGA CVE-2026-48096).
- **etag-eager / size-lazy:** `etag` бампим ВВЕРХ эагерно одним set-based стейтментом на любой мутации потомка (дешёвый сигнал для клиентов); `size` пересчитываем лениво/фоном (`size_bytes=-1` dirty-маркер + sweeper).
- **Arch-бан рекурсивной агрегации потомков в request-путях:** запрет `whereHas('descendants')`/`withCount('descendants')` (ломается на MariaDB — нет коррелированных CTE; деградирует на PG); счётчики — через денорм/`EXISTS` по path-prefix; всегда `withMaxDepth()` при рекурсии.

## H.5 Расширяемость — type-safe, без god-object (D21′/D22)
- **По одному `final` bounded-generic реестру на концерн** (`DocumentHandlerRegistry`, `ExporterRegistry`, `StorageTypeRegistry`, `PanelRegistry`), каждый `@template T`, контейнер-bound с явными return-типами; **`VaulterManager` = тонкий делегирующий фасад**, его публичная поверхность заморожена Pest-arch-тестом (чтобы новые `registerXxx()` не раздували его).
- **Реестр (string-ключи) = источник истины форматов**, `NodeKind`/`DocumentType` enum — **только встроенный сахар**; на границе Action валидировать по реестру, не `guardType(DocumentType)` (иначе runtime-формат недостижим). Вместо `registerDocumentType(string,array)` — интерфейс `DocumentHandler` + `final readonly FormatDescriptor`.
- **Pipeline-вето с tx-сплитом:** `before*`-pipes (могут **вето**, до `DB::transaction`) vs `within`/`after`-pipes (внутри транзакции, обогащают модель). `interface NodePipe { handle(NodeContext $ctx, Closure $next): NodeContext; }`, порядок — позицией в массиве (документировать). Закрывает дыру «`final readonly` Actions эмитят события только после commit — потребитель не может вето/мутировать create».
- **BC/версии:** контекстный биндинг — Laravel `^12.0`-safe (`$app->when(Action::class)->needs(Contract::class)->give(...)`, не делать `#[Give]`-атрибут несущим); **Filament 5** API (`PanelProvider`+`bootUsing()`+`configureUsing()`; **нет `getCurrentPanel()`** в v5; `tenant(ownershipRelationship:)` по имени); типизированный `VaulterPlugin::current(Panel): self` (локализовать небезопасный каст `getPlugin(): Plugin`). Каждый новый контракт (`DocumentHandler`/`NodePipe`/`HostRoleDriver`/`AccessResolver`) — **SemVer-замороженный public API** с момента релиза.
- **`src/Testing/` (не `tests/`):** `NullRoleDriver` (env-gated open), `FakeContentStore`, `RecordingCollaborationDriver`, дефолтные форматы — чтобы **потребители** тестировали свои расширения; `mergeConfigFrom` гарантирует boot без host-конфига.

## H.6 Добавки к Verification (§12)
config-cache arch-гейт; тест middleware-до-`SubstituteBindings`; queue-изоляция панели (job панели B оперирует B после восстановления контекста); CAS кросс-scope-удаление (два scope, одинаковые байты, удаление A сохраняет объект B); ACL deny-вето-после-role-grant (табличный); arch-тест публичной поверхности `VaulterManager`; arch-бан коррелированного CTE (MariaDB); коллизия ключа ACL-кэша (два разных запроса ≠ один ключ); FQCN-валидация панели на boot; воскрешение блоба `TOMBSTONED→COMMITTED` под локом.

## H.7 Разрешения открытых вопросов
Q4 (deny-ACE) → оставить deny как **вето-после-max, explicit>inherited** (D7′). Q5 (PK) → ulid подтверждён, per-panel выбор только в own-tables (D9′). Q6 (document-блобы) → без изменений; если вложения документов когда-либо пойдут в `v_blobs` — GC/ACL применяются идентично.

---

# Addendum A — Hocuspocus-server как внешний переиспользуемый образ (детальный план; P4 #15)

> Источник истины по задаче «контейнер хаба». Заземлено на ресёрч (Perplexity: Hocuspocus
> prod-deploy + WS-security + Docker-distribution) и API @hocuspocus/server v2.15 (context7).
> Цель — закрыть слепые зоны, особенно по БЕЗОПАСНОСТИ. Реализуется на ветке
> `feat/hocuspocus-external-image`, ритм: implement → review[node --check/--test + boot-smoke]
> → fix → commit; затем code-review-workflow.

## A.1 Модель дистрибуции (инвариант)
Хаб — **бэкенд-агностичная инфраструктура**: ОДИН опубликованный OCI-образ
`ghcr.io/axioma-studio/vaulter-hocuspocus:<semver>` обслуживает РАЗНЫЕ проекты. Потребитель
**ссылается** (`image:` в своём compose; в проде — `@sha256:`-digest), конфигурирует ТОЛЬКО
через env, **не вкладывает исходники и не печёт секреты** в образ. Образ environment-agnostic
и secret-free. Авторизацию/персист хаб делегирует вебхукам конкретного Laravel
(`{BACKEND}/{PREFIX}/collab/{authenticate,store}`); JWT-секрет хабу НЕ нужен (он лишь релеит токен).

## A.2 Env-контракт (полный, 12-factor; fail-fast валидация на старте)
**Есть:** `VAULTER_COLLAB_PORT`, `VAULTER_COLLAB_HEALTH_PORT`, `VAULTER_BACKEND_URL`,
`VAULTER_API_PREFIX`, `VAULTER_LARAVEL_WEBHOOK_URL`, `VAULTER_COLLAB_WEBHOOK_SECRET`,
`VAULTER_COLLAB_WEBHOOK_TIMEOUT_MS`.
**Добавить:**
- `VAULTER_COLLAB_ALLOWED_ORIGINS` — csv точных origin'ов; **обязателен в проде**; пусто ⇒
  reject всех браузерных; `*` — только dev и с явным warning в логах (CSWSH-защита).
- `VAULTER_COLLAB_MAX_PAYLOAD_BYTES` — лимит кадра (default 4 MiB; НЕ ws-дефолт 100 MiB).
- `VAULTER_COLLAB_THROTTLE` / `_THROTTLE_SECONDS` / `_BAN_TIME` — Throttle-ext (per-IP).
- `VAULTER_COLLAB_TIMEOUT_MS` (30000), `_DEBOUNCE_MS` (5000), `_MAX_DEBOUNCE_MS` (30000).
- `VAULTER_COLLAB_REDIS_URL` (опц.) ⇒ включает Redis-ext (multi-instance) + `VAULTER_COLLAB_INSTANCE_NAME`.
- `VAULTER_COLLAB_QUIET` (bool, default true в проде) / `VAULTER_COLLAB_LOG` (on/off Logger-ext).
- `VAULTER_COLLAB_TRUST_PROXY` (учёт `X-Forwarded-*` за reverse-proxy).
Старт валидирует обязательные (в проде: backend-url, allowed-origins) и **падает с понятной
ошибкой**, если их нет.

## A.3 Security-чеклист (по убыванию важности — закрыть слепые зоны)
1. **CSWSH / Origin-allowlist** — валидировать `Origin` в `onConnect({ request })` против
   allowlist (ТОЧНОЕ совпадение, без wildcard/substring); reject отсутствующего Origin для
   браузерных; throw → refuse. ⚠ СЕЙЧАС ОТСУТСТВУЕТ — главная дыра.
2. **TLS/wss** — терминировать на reverse-proxy (nginx/traefik), блокировать plaintext в проде;
   хаб слушает `ws` за proxy. Preserve `Host`/`X-Forwarded-Proto`/client-IP. Сниппеты в доке.
3. **maxPayload** — `websocketOptions.maxPayload` (~4 MiB) + лимит на proxy. ⚠ отсутствует.
4. **Throttle** — `@hocuspocus/extension-throttle` ПЕРВЫМ в `extensions` (per-IP handshake-rate
   + ban). ⚠ отсутствует. (Throttle ≠ max-concurrent — жёсткие per-user/doc/tenant капы — бэклог.)
5. **perMessageDeflate: false** — снизить attack surface/ресурсы. ⚠ дефолт.
6. **Heartbeat/idle** — Hocuspocus `timeout` (ping/pong, dead-peer); **выровнять с LB idle-timeout**
   и proxy websocket-timeout, иначе посредник рвёт живые сокеты.
7. **Auth fail-closed** — уже: webhook `onAuthenticate` (JWT verify в Laravel, readOnly давится
   на сокете); `allowed:false`/non-2xx ⇒ refuse. JWT TTL короткий (3600).
8. **No baked secrets** — env-only; `webhook_secret`/`redis_url` инъектятся в рантайме; образ secret-free.
9. **Non-root + minimal** — `USER node`, alpine/distroless, pinned base, `npm ci` из lockfile.
10. **Graceful shutdown** — SIGTERM/SIGINT → readiness=false → стоп новых upgrades → дать
    debounce-окну сброситься → `server.destroy()` → exit; выровнять с preStop/drain ингресса.
11. **Supply chain** — pinned deps, `npm audit` в CI, SBOM + build-provenance attestation.

## A.4 Образ (Dockerfile)
Multi-stage (deps→runtime); `node:22-alpine` pinned (+digest опц.); **`npm ci` из КОММИЧЕННОГО
lockfile** (сейчас `package-lock.json` gitignored глобально — разигнорить
`hocuspocus-server/package-lock.json`); `USER node` (non-root); OCI-labels
(`org.opencontainers.image.source/description/licenses`); `.dockerignore`; HEALTHCHECK (есть);
EXPOSE 1234/1235. Опц. distroless-рантайм + отдельный debug-таргет.

## A.5 CI publish (часть P2 #4)
`.github/workflows/hocuspocus-image.yml`: на тег `vaulter-hocuspocus-v*` → `docker/metadata-action`
(semver-теги `1`/`1.2`/`1.2.3`) + buildx multi-arch (`linux/amd64,linux/arm64`) + login ghcr
(`GITHUB_TOKEN`) + `build-push-action` (`provenance:true, sbom:true`) + `actions/attest@v4`.
В проде потребители пиннят `@sha256:`-digest. (Сам публикующий прогон — под P2 #4: тег+secret.)

## A.6 Документация
`docs/guide/hocuspocus-server.md` + пункт в VitePress-сайдбаре (en; ru-перевод — в P3 #7):
архитектура (ws↔webhook диаграмма), **полный env-контракт таблицей**, security (TLS/wss/origin/
throttle/maxPayload + nginx/traefik сниппеты), standalone-запуск (`docker run` / compose с `image:`),
scaling (Redis multi-instance + LB sticky vs Redis pub/sub, caveat «all messages on all instances»),
graceful shutdown, **multi-project reuse** (один образ — N проектов). README хаба остаётся
quick-ref со ссылкой на эту страницу.

## A.7 Явно НЕ в этой итерации
Реальный publish образа в ghcr (нужен тег/secret — P2 #4); полноценный Redis-стенд e2e (опц.);
per-tenant/-doc concurrent-caps (бэклог); distroless (опц., после alpine-базы).

## A.8 Verification
`npm run check` + `node --test` (хаб); локальный `docker build` хаба (как в e2e — без pdo);
boot-smoke: origin-reject (чужой Origin → refuse), maxPayload, graceful shutdown; существующий
e2e-стенд P1.1 остаётся зелёным (хаб с дефолтным `ALLOWED_ORIGINS` для localhost).
