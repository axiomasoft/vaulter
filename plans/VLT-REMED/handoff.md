# HANDOFF — 2026-07-11 — after P5.1

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — Blocker R-01 требует решения по публичному контракту TipTap-контента |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Дополнить ТЗ **P5.2** ре-дизайном барьера P0.1 (набор узлов/марок · расширяемость хостом · strip-vs-throw на чтении · BC-запись) — или вынести это в новый item P5.4. Остальные 9 находок реестра P5.1 исполнимы в P5.2 как есть. |

```
/task:plan-design VLT-REMED P5.2
```

> **Почему plan-design, а не сразу plan-exec.** P5.1 закрыт `🔴 Blocked` с `Escalation Needed: yes`
> (trigger `public contract/SemVer`, closed enum `plan-protocol` §10). Находка **R-01 (Blocker)** не
> чинится «минимальной правкой», которую предписывает Scope Included P5.2, — нужен пересмотр
> контракта TipTap-контента. Если владелец решит, что ре-дизайн укладывается в P5.2 как есть,
> — снять эскалацию и идти `/task:plan-exec VLT-REMED P5.2` (**opus/high**, см. ниже).

**Done:**
- **Фазы P0 (3/3 🟢) · P1 (4/4 🟢) · P2 (3/3 🟠) · P3 (6/6 🟢) — ТЕРМИНАЛЬНЫ.**
- **Фаза P4 (W4) — 7/10 🟢, НЕ терминальна:** P4.1 🔴 Blocked (F-P4.5-02, hocuspocus-мажор,
  ⛔ Skipped by decision); **P4.9 / P4.10 — ⬜ Not started** (канон структуры + namespace-refactor,
  сознательно не отданы автономному прогону). P5 исполняется поверх незакрытой P4 по директиве
  владельца (**D26**) — P5 их НЕ подменяет и НЕ закрывает.
- **P5.1 (сквозное adversarial-ревью, read-only) — 🔴 Blocked (работа СДАНА полностью).**
  🔴 — формальный статус инварианта plan-lint (`Escalation Needed=yes ⇒ Status=🔴`), а не
  недоделка: единственный Deliverable — реестр находок — создан. Тот же паттерн, что у P4.1.
  - **Реестр:** `plans/VLT-REMED/findings/P5.1-review-findings.md` — **10 находок:
    1 Blocker · 6 Major · 3 Minor · 0 Nit**, ВСЕ `CONFIRMED` (ни одной `PLAUSIBLE`; четыре
    доведены до отказа исполнением кода, не чтением).
  - **Код НЕ изменён** (D11): `git status --porcelain -- packages docs .github hocuspocus-server
    UPGRADE.md composer.json …` → ПУСТ.
  - **Baseline-гейт на момент ревью:** `analyse` 0 errors · `test` **471/471** · `test:types`
    **100 %** · `lint:check` красный ТОЛЬКО на `plans/AUDIT-VLT/artifacts/repro/**` (29 файлов,
    пред-существующий долг вне scope).
  - **Ось (б) red-before — 8 проверок** (требовалось ≥6), 7 честно красных; восьмая — находка R-07.
  - **Ось (а): P0.2 (SSRF) — ЧИСТО** (проверено откатом `DeliverWebhook.php`: 5 delivery-path-тестов
    краснеют). **P0.1 и P0.3 — несут живые дефекты** (R-01, R-02).
  - **Ось (д) — `n/a`** (D25), проверено механически: 0 изменённых `namespace`, 0 переименований.

**Remaining:**
1. **P5.2** — ремедиация 10 находок реестра. **Гейт входа:** снять/отработать эскалацию P5.1 (см.
   Next). **Модель — `opus/high`, не дефолтный sonnet/medium:** `plan.md` §Routing предписывает
   opus/high, «если реестр P5.1 несёт Blocker в ядре авторизации / CAS / tus» — **R-02/R-03 суть
   Blocker-класс в tus/CAS**.
2. **P5.3** — closure-матрица 103 находок + вердикт release-readiness (sonnet/medium).
   Ожидаемый и КОРРЕКТНЫЙ исход — **«НЕ ГОТОВ К РЕЛИЗУ»** (D26): P4.1 🔴, P4.9/P4.10 ⬜,
   провизорный MSI, `CHANGELOG.md` не наполнен.
3. **Вне P5** (остаются открытыми items фазы P4): **P4.9**, **P4.10** — под надзором, opus/high;
   решение владельца по **F-P4.5-02** (hocuspocus v4).

**Sources of truth:**
- `plans/VLT-REMED/findings/P5.1-review-findings.md` — **SSOT входа P5.2** (10 находок, семь осей
  с вердиктами, рекомендованная диспозиция по каждой, группировка фиксов).
- `plans/VLT-REMED/plan.md` (Status Board: P4 7/10 🔴, P5 0/3 🔴 · Decision Log **D1–D26** · Update Log).
- `plans/VLT-REMED/phases/P5.md` (P5.1 закрыт с ESCALATION-блоком + Completion Notes; P5.2/P5.3 — ТЗ).
- `plans/VLT-REMED/phases/{P0,P1,P2,P3,P4}.md` (Completion Notes 23 закрытых items — «заявленное»).
- `plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only — VLT-REMED в него НЕ пишет, D13).
- `plans/VLT-REMED/MORNING-QUEUE.md` (§1 F-P4.5-02 · §2 P4.9/P4.10 · §3 CHANGELOG · §4 UPGRADE.md
  — **§4 ПОДТВЕРЖДЁН и расширен находкой R-06** · §5 принятый мелкий долг).

**Open risks:**
- **R-01 (Blocker) — самый дорогой итог ревью.** Фикс P0.1 сделал невозможным сохранение документа
  с `image`/`table`/`underline` — узлами, которые пакет САМ объявляет редактору
  (`DocumentType #[TiptapExtensions(['StarterKit','Image','Table','Link'])]`), а существующий
  контент с ними **падает на ЧТЕНИИ** (`NodeDocumentVersion::tipTapContent()` бросает вопреки
  собственному докблоку «decays to an empty doc»). Хост расширить allow-list не может
  (`private const`, конфиг-шва нет). Чинить по КЛАССУ: развести «безопасность схем `href`»
  (сделано верно, сохранить) и «полнота модели документа» (сломано).
- **R-02/R-03 (Major, tus/CAS).** Ось (а) ТЗ требовала, чтобы tus-лок покрывал **и sweep** — он не
  покрывает: `SweepUploadsCommand` → `expire()` сносит staged-объект и переводит сессию в `Expired`
  БЕЗ лока, параллельно с PATCH; `StagedUpload::append()` при отсутствующем staged-объекте
  **fail-OPEN** (молча теряет префикс) → тихая порча файла + воскрешение терминальной сессии.
  Регресс-тест — только на PG (шов `packages/drive/tests/Concurrency/` уже есть).
- **R-04 (Major).** Markdown-экспорт экранирует HTML, а не Markdown → `javascript:`-ссылка
  проходит ОБА эшелона D4. Два рабочих вектора приведены в реестре (исполнены).
- **R-05 (Major).** `vaulter.webhooks.follow_redirects => true` снимает SSRF-барьер целиком
  (redirect-хоп резолвится заново, `CURLOPT_RESOLVE` пинит только исходный хост).
- **R-06 (Major).** `UPGRADE.md` не имеет секций P0.1 / P1.1 / P1.3 — включая самый host-видимый
  BC всего плана. Сверять надо ОТ Completion Notes каждого item'а К файлу, а не грепом по F-id
  (греп ничего не найдёт, если секции нет вовсе) — ровно это и предсказал MORNING-QUEUE §4.
- **Урок P5.1 для будущих ревью:** гипотеза «фикс не вклинен» ОБЯЗАНА проверяться откатом
  не-тестового файла и прогоном ПОЛНОГО сьюта пакета, а не `--filter` по имени класса. Ложная
  находка «SSRF-guard не вклинен в DeliverWebhook» родилась именно из `--filter DeliverWebhook`
  (SSRF-тесты живут в `OutboundUrlGuardTest`) и была отброшена. Аналогично «F-P4.3-05/F-P4.4-03
  не закрыты» — закрыты, просто без F-id-маркера.
- **Снаги харнесса (унаследованы):** core-тесты гоняются ИЗ КОРНЯ (`vendor/bin/pest --filter=X`,
  вывод — JSON/PAO), сателлиты — `cd packages/<pkg> && vendor/bin/pest <path>` (человекочитаемый
  вывод). `packages/core` своих composer-скриптов НЕ имеет. PG-прогон — `php8.4` + БД
  `vaulter_test` в контейнере `agelto_postgres` (127.0.0.1:5455). Нет coverage-драйвера
  (xdebug/pcov) → реальный MSI локально не измерим. `cache.default => 'array'` в
  `defineEnvironment()`; фиче-флаги маршрутов — до boot.
- **Пред-существующие флейки (вне scope):** `MassAssignmentHardeningTest` (count-зависимый, только
  PG) · `NodeCommentSchemaTest` (jsonb key-order, только PG).
- В рабочем дереве есть НЕ относящиеся к VLT-REMED правки (`.claude/`, `plans/ACTIVE.md`,
  `plans/AUDIT-VLT/*`) из конкурентной сессии/хука — НЕ трогать, коммитить только scoped-пути item'а.

**Workarounds/Deferred/Open questions:**
- **workarounds:** core-тесты — из корня (`--filter`, JSON-вывод), сателлиты — из своей папки ·
  red-before верифицируется откатом ТОЛЬКО не-тестовых файлов (`git checkout <base> -- <src>` →
  прогон → `git checkout HEAD -- <src>`), а не `git stash` (в дереве живут чужие правки) ·
  PG-сценарии — `php8.4` + `agelto_postgres` · провизорный MSI-порог 50/70 · `lint:check` красный
  на `plans/AUDIT-VLT/artifacts/repro/**` — известный долг, closure НЕ блокирует.
- **deferred:** `CHANGELOG.md`-наполнение (deny-правило, D23 — сессия с доступом) · **P4.9 / P4.10**
  (канон структуры + namespace-refactor, под надзором) · **F-P4.5-02** (hocuspocus v4 — отдельный
  план по решению владельца) · реальный измеренный MSI (прогон в CI) · остаточный
  `npm audit high` в `packages/vue` (апстрим `vite-plugin-dts`) · пред-существующий Rector-долг ·
  runtime-guard на разрыв connection для 4 моделей `drive`/`documents` (MORNING-QUEUE §5) ·
  архивация AUDIT-VLT (`/task:plan-close archive AUDIT-VLT`).
- **open_questions:** **Q5 (НОВЫЙ, блокирует P5.2)** — как чинить R-01: (а) расширить allow-list до
  набора, который пакет отдаёт редактору, + конфиг-шов для хоста, + strip вместо throw на чтении;
  (б) сузить `DocumentType #[TiptapExtensions]` до реально поддерживаемого набора (убрать
  `Image`/`Table` из обещаний редактору); (в) иное. Требует решения владельца — это публичный
  контракт + BC. · Q3 (ротация `signing_secret`) и Q4 (абсолютный барьер wall-deny) — по-прежнему
  `Decision pending`, P5 не блокируют. · Q1/Q2 — `Resolved → D1/D10`.
