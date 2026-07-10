# P0 — Разбор внешнего аудита плана + RAG-добивка пропущенных осей

**Дата:** 2026-07-10 · **Автор:** issue-planner/opus
**Источник разбора:** внешний аудит плана AUDIT-VLT (Perplexity, 15 рекомендаций)
**Инструменты верификации:** perplexity-web `search_advanced` ×3 · context7 `/websites/laravel_12_x` ·
факты репозитория (чтение `composer.json`, конфига, дерева тестов)

Каждая рекомендация получила вердикт. Отклонение обосновано фактом, а не вкусом.
Принятые — разнесены по items; ссылки в колонке «Куда ушло».

---

## 1. Вердикты по рекомендациям внешнего аудита

| # | Рекомендация | Вердикт | Обоснование | Куда ушло |
|:--|:--|:--|:--|:--|
| 1 | Multi-tenancy isolation check | ✅ **принято, переформулировано** | Реальный пробел: ни один item не проверял tenant-скоуп запросов к БД. Формулировка аудита неточна — `SoftDelete` не имеет отношения к tenant-изоляции; global scope в пакете вообще может быть анти-паттерном (ломает GC/reconcile). Проверять надо не «есть ли scope», а «может ли актор одного тенанта достать строку другого». | новый item **P1.9** |
| 2 | Rate limiting на публичных маршрутах | ✅ **принято** | `PublicShareController`, tus `HEAD`/`PATCH`, collab-handshake доступны без `auth`; throttle нигде не проверялся. RAG:✅ 2026-07-10 (context7 `/websites/laravel_12_x` — `throttle` middleware + `RateLimiter::for`). | **P1.8 `C15`** |
| 3 | Migration safety: NOT NULL без default = Blocker, DROP = Major | ⚠️ **принято частично** | Миграции пакета — create-only (`000007`…`000022`), таблицы пустые: блокировка при `NOT NULL` там невозможна. Ось реальна для БУДУЩИХ версий и для одной фактически деструктивной миграции `000023_drop_legacy_docvault_tables`. Правильные вопросы: есть ли `down()`, идемпотентна ли миграция, объявлена ли политика апгрейда схемы. Место — P2.3 (миграции), не P4.1 (индексы). | обязательная ось **P2.3** |
| 4 | Создать `REGISTER.md` scaffold в P0.3 | ❌ **отклонено** | Файл `plans/AUDIT-VLT/findings/REGISTER.md` уже создан на этапе проектирования, с шапкой таблицы и правилом append-only. Рекомендация основана на неполном чтении дерева плана. | — |
| 5 | Filament-панель как attack surface | ✅ **принято** | `packages/filament/src/Resources/*` + `Support/PanelActor.php` — отдельная поверхность: Filament-actions имеют собственный `authorize()` и могут не проходить через `AccessResolver`; bulk-операции и таблицы могут раскрывать `storage_key`/`sha256`. Ранее было в Scope Excluded фазы P1 — это и есть pre-mortem R5.1 («покрыли PHP-ядро, а дыра в необследованном компоненте»). | новый item **P1.10** |
| 6 | CORS-конфигурация маршрутов пакета | ✅ **принято** | RAG:✅ 2026-07-10 (context7 `/websites/laravel_12_x`): `config/cors.php` **не публикуется по умолчанию** в Laravel 11/12; дефолтный `paths` = `['api/*', 'sanctum/csrf-cookie']`, `allowed_origins` = `['*']`. `config vaulter.route_prefix` = `api/vaulter` → маршруты пакета **автоматически** попадают под CORS с `*`. Это факт, а не гипотеза. | **P1.8 `C16`** |
| 7 | Inter-package constraints: `*`/`@dev`, циклы, PHP-минимум, filament в `require` | ❌ **отклонено в основной части**, ✅ принято в части CI-матрицы | Факт (чтение всех шести `composer.json` 2026-07-10): межпакетные констрейнты — везде `self.version` (канон монорепо, не `*` и не `@dev`); `php: ^8.4` во всех шести; `filament/filament: ^5.0` уже в `require` пакета filament. Циклов нет: `core` тянет drive/media только в `require-dev`. Реальная асимметрия, которую аудит не заметил: `orchestra/testbench` = `^10.0\|^11.0` везде, но `^11.0` в filament; `illuminate/*` = `^12.0\|^13.0`. Это вопрос CI-матрицы, а не констрейнтов. | обязательная ось **P4.4** |
| 8 | Arch-тесты layer isolation | ❌ **отклонено в части «их нет»**, ✅ принято в части «что они проверяют» | Факт: `tests/Arch/ArchTest.php` присутствует во ВСЕХ пяти PHP-пакетах (core, drive, media, documents, filament). Открыт вопрос содержания: покрывают ли они запрет `App\*`, однонаправленность зависимостей и границу публичного API. | обязательная ось **P4.3** |
| 9 | P5.3 — чеклист верификации pre-mortem-митигаций | ✅ **принято, с исправлением критерия** | Идея верна. Критерий аудита «`git log --all -- packages/` пуст» неверен: ветка содержит рефакторинг ядра, история непуста по определению. Корректный критерий: между первым и последним коммитом аудита нет коммитов, изменяющих `packages/`. | обязательная ось **P5.3** |
| 10 | Явный триггер развёртывания скелетов P2–P5 | ✅ **принято** | Дёшево и снимает двусмысленность. (Замечание «скелеты нарушают DoR ещё до старта» — неверно: `plan-protocol` §5 легализует пустые поля при скелет-маркере; DoR закрывается в момент развёртывания.) | **§2 Execution Rules** |
| 11 | Routing-таблица не покрывает P2–P4 | ❌ **отклонено** | В `plan.md` §3 уже есть строка `P0.1, P0.3, P1.2–P1.5, P1.7, P1.8, P2.*, P3.*, P4.* \| sonnet/medium (дефолт)`. | — |
| 12 | CHANGELOG + `composer.json` publishable-мета | ✅ **принято** | Пакет идёт к первому релизу; `CHANGELOG.md` в корне есть, но per-package changelog и полнота Packagist-меты не проверялись. | обязательная ось **P2.1** |
| 13 | Artisan publish/install-команды | ✅ **принято** | Прямой DX-gap и одновременно проверка D13: путь включения collab обязан существовать как команда, а не как абзац в README. | обязательная ось **P2.5** |
| 14 | P5.1 — sanity-критерий «0 находок» | ✅ **принято** | Дополняет D3/`C`-чеклисты на уровне синтеза: ноль находок по всей фазе P1 обязан быть обоснован, а не констатирован. | обязательная ось **P5.1** |
| 15 | D16 — нормативность `findings/P0-*.md` | ✅ **принято** | Правило есть в Scope Excluded P0.2, но как plan-level decision оно грепается и переживает развёртывание скелетов. | **D16** |

---

## 2. Оси, пропущенные И планом, И внешним аудитом (найдены собственным RAG)

Внешний аудит целиком сосредоточился на процессе и конфигурации, но не тронул
**контентную поверхность DMS** — при том что vaulter хранит и рендерит документы.

### G1. XML/архивы: XXE и decompression-бомбы

RAG:✅ 2026-07-10 (php.net migration80.deprecated, php.net `libxml_set_external_entity_loader`,
php.watch/versions/8.0/libxml_disable_entity_loader-deprecation):

- G1.1 На PHP 8.4 (libxml ≥2.9 обязателен) загрузка внешних сущностей **выключена по умолчанию** — классический XXE через `SYSTEM "http://…"` не работает «из коробки». `libxml_disable_entity_loader()` устарел с PHP 8.0 и в новом коде не нужен.
- G1.2 **Остаётся уязвимым** `LIBXML_NOENT` (и `LIBXML_DTDLOAD`/`LIBXML_DTDVALID`): при них подстановка сущностей возвращает XXE и «billion laughs». Явная формулировка php.net: «no longer needed … **unless the (still vulnerable) LIBXML_NOENT is used**».
- G1.3 PHP 8.4 + libxml ≥2.13 дают флаг `LIBXML_NO_XXE` — усиление по умолчанию там, где нужны `LIBXML_NOENT`/DTD.
- G1.4 DOCX/XLSX — ZIP с XML внутри: XXE зависит не от `ZipArchive`, а от опций парсинга XML. Зато `ZipArchive` **не защищает** от zip-бомб: лимиты на суммарный распакованный размер, число записей и глубину вложенности обязаны быть в коде приложения.
- G1.5 SVG — это XML: и XXE-поверхность на сервере, и XSS-поверхность в браузере.

**Проекция на vaulter:** `UniverHandler` (таблицы), экспортёры, любой парсинг xlsx/docx/svg.
Проверяется в новом item **P1.11**.

### G2. Stored XSS через TipTap-JSON и его серверный рендер

RAG:✅ 2026-07-10 (tiptap.dev/docs/guides/output-json-html, tiptap.dev link extension,
GHSA-hmw2-7cc7-3qxx / CVE-2025-14284):

- G2.1 TipTap прямо документирует: JSON **не безопаснее** HTML, «malicious content can be sent either way», фреймворк не является границей безопасности.
- G2.2 **CVE-2025-14284**: `@tiptap/extension-link` до **v2.10.4** принимал произвольный `href`, включая `javascript:` → stored DOM-XSS. Патч добавил allow-list схем (`isAllowedUri`). Защита покрывает только официальное расширение: собственная link-подобная mark возвращает дыру.
- G2.3 Схема ProseMirror отбрасывает неизвестные теги/атрибуты, но **не санирует значения** разрешённых (`href`, `src`, `style`).
- G2.4 При серверном рендере JSON→HTML санитизация обязательна на двух слоях: на входящем HTML (если импорт разрешён) и на исходящем HTML перед отдачей.

**Проекция на vaulter:** `packages/vue/package.json` объявляет `@tiptap/starter-kit: ^2.10`
и `@tiptap/vue-3: ^2.10` — констрейнт допускает уязвимые `2.10.0…2.10.3`; фактическую
версию решает lock-файл. Серверный рендер — `Support/TipTapRenderer.php`,
`Export/HtmlExporter.php`. Проверяется в **P1.11**.

### G3. Заголовки ответа при скачивании

RAG:✅ 2026-07-10 (MDN `X-Content-Type-Options`, RFC 6266, security.stackexchange
header-injection):

- G3.1 `X-Content-Type-Options: nosniff` обязателен на всех отдачах пользовательского контента: без него браузер MIME-снифает polyglot (валидный PDF + валидный HTML) и исполняет его как HTML/JS.
- G3.2 Пользовательский контент отдаётся как `Content-Disposition: attachment` с не-HTML `Content-Type`; inline-превью — только в песочнице (отдельный origin или `<iframe sandbox>` + строгий CSP).
- G3.3 `filename` в `Content-Disposition` — недоверенный ввод: `\r`, `\n`, `"` и управляющие символы < 0x20 обязаны экранироваться или отвергаться (CRLF-инъекция в заголовок).
- G3.4 Тип файла валидируется по содержимому, а не по расширению.

Проверяется в **P1.11** (пересечение с P1.3 `C13`).

### G4. Remanence: корзина, версии, экспорт

RAG:✅ 2026-07-10 (перечень слепых зон аудитов DMS — purplesec, material.security,
pericent):

- G4.1 «Удалённые» файлы остаются доступны по прямым URL, старым share-ссылкам и API по object-id; тест «ссылка после soft-delete» аудиты обычно не делают.
- G4.2 История версий хранит чувствительный контент даже после «зачистки» текущей версии; версионные API из скоупа обычно выпадают.
- G4.3 Индексы, превью-кеши и OCR-кеши удерживают фрагменты удалённых документов при более слабом контроле доступа.
- G4.4 Bulk-экспорт и массовое скачивание обходят гранулярные ACL и rate-limit — один скомпрометированный аккаунт выносит всё.
- G4.5 Кеш решений авторизации: ключ кеша без tenant-id/identity даёт межтенантную утечку; отзыв прав не инвалидирует кеш.

**Проекция на vaulter:** `config vaulter.trash.retention_days = 30`, `PurgeTrashCommand`,
`FileVersion`/`NodeDocumentVersion`, `NodeExportService`, `Export/*`.
Проверяется в новом item **P1.12**.

---

## 3. Что осталось за границей

- Аудит плана не проверял CI-конфигурацию (`.github/`) — вне D1, ось остаётся в P4.4.
- Антивирус/CDR для загружаемых файлов: в vaulter не заявлен; вопрос «нужен ли» — продуктовое решение, не дефект. Фиксируется в P1.11 как `n/a` с обоснованием, если сканирование не заявлено.
- Кеш решений авторизации (G4.5) проверяется только на факт наличия кеша; если кеша нет — `n/a`.

---

## Источники

1. https://www.php.net/manual/en/migration80.deprecated.php
2. https://www.php.net/manual/en/function.libxml-disable-entity-loader.php
3. https://php.watch/versions/8.0/libxml_disable_entity_loader-deprecation
4. https://www.php.net/manual/en/function.libxml-set-external-entity-loader.php
5. https://tiptap.dev/docs/guides/output-json-html
6. https://tiptap.dev/docs/editor/extensions/marks/link
7. https://github.com/advisories/GHSA-hmw2-7cc7-3qxx (CVE-2025-14284)
8. https://developer.mozilla.org/docs/Web/HTTP/Reference/Headers/X-Content-Type-Options
9. https://laravel.com/docs/12.x/routing (throttle, RateLimiter::for) — через context7 `/websites/laravel_12_x`
10. https://laravel.com/docs/12.x/sanctum (`config/cors.php` не публикуется по умолчанию) — через context7
11. https://purplesec.org/knowledge-base/web-attacks/file-upload-attacks/limited-uploads/
12. https://material.security/workspace-resources/10-dlp-blind-spots-putting-your-cloud-data-at-risk-today
