# HANDOFF — 2026-07-11 — after P5.1

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | **opus** |
| Thinking | **high** — реестр P5.1 несёт Blocker-класс в контракте TipTap (R-01) и в tus/CAS (R-02/R-03); §Routing прямо предписывает opus/high |
| Context | fresh session — handoff свежий, рекомендован `/clear` |
| Суть | Исполнить **P5.2**: ремедиация 10 находок сквозного ревью, разложенных на 4 шва — **G2** (критическая секция upload-сессии: sweep/expire/abort под тем же локом, что PATCH; `append()` fail-CLOSED; `advanceReceived()` CAS), **G1** (контракт TipTap-контента по решению владельца **D27**: синхрон allow-list с `#[TiptapExtensions]` + конфиг-шов + decay-on-read + markdown-экранирование по классу), **G3** (SSRF: удалить `webhooks.follow_redirects`, не следовать редиректам безусловно — D30), **G4** (`UPGRADE.md`: три недостающие секции + BC самого P5.2 + guard-тест «дока ↔ код»). ТЗ — `phases/P5.md` item P5.2 (16 полей заполнены, DoR закрыт). |

```
/task:plan-exec VLT-REMED P5.2
```

> **Эскалация P5.1 РАЗРЕШЕНА** решением владельца (**D27** + следствия D28/D29/D30; `open-questions.md` Q5 → `Resolved → D27`). Ре-дизайн барьера P0.1 исполняется ВНУТРИ P5.2 (группа G1) — отдельный item не заводится. Решение исполняется как данность, переобсуждению не подлежит.

**Done:**
- **Фазы P0 (3/3 🟢) · P1 (4/4 🟢) · P2 (3/3 🟠) · P3 (6/6 🟢) — ТЕРМИНАЛЬНЫ.**
- **Фаза P4 (W4) — 7/10 🟢, НЕ терминальна:** P4.1 🔴 Blocked (F-P4.5-02, hocuspocus-мажор, ⛔ Skipped
  by decision); **P4.9 / P4.10 — ⬜ Not started** (канон структуры + namespace-refactor, сознательно
  не отданы автономному прогону). P5 идёт поверх незакрытой P4 по директиве владельца (**D26**) —
  НЕ подменяет и НЕ закрывает их.
- **P5.1 (сквозное adversarial-ревью, read-only) — 🔴 Blocked (работа СДАНА полностью).** 🔴 — формальный
  инвариант plan-lint (`Escalation Needed=yes ⇒ 🔴`), а не недоделка. Реестр:
  `findings/P5.1-review-findings.md` — **10 находок: 1 Blocker · 6 Major · 3 Minor**, все `CONFIRMED`.
  Код не изменён (D11). Baseline-гейт на момент ревью: `analyse` 0 errors · `test` 471/471 ·
  `test:types` 100 % · `lint:check` красный ТОЛЬКО на `plans/AUDIT-VLT/artifacts/repro/**`.
- **P5.2 — ТЗ детализировано (DoR закрыт, 2026-07-11):** scope расширен решением владельца
  (D27–D29 — контракт TipTap; D30 — структурный фикс SSRF-редиректов), 10 находок разложены на 4 шва,
  на каждую находку — обязательный регресс-тест, гоняющийся в реальном CI-лейне.

**Remaining:**
1. **P5.2** (opus/high) — ремедиация 10 находок ревью. 4 коммита (G2 → G1 → G3 → G4), латиница в типе
   коммита. Выход — диспозиционная таблица на 10 строк в Completion Notes (вход для P5.3).
2. **P5.3** (sonnet/medium) — closure-матрица 103 находок + вердикт release-readiness. Ожидаемый и
   КОРРЕКТНЫЙ исход — **«НЕ ГОТОВ К РЕЛИЗУ»** (D26): P4.1 🔴, P4.9/P4.10 ⬜, провизорный MSI,
   `CHANGELOG.md` не наполнен.
3. **Вне P5** (открытые items P4): **P4.9**, **P4.10** — под надзором, opus/high; решение владельца по
   **F-P4.5-02** (hocuspocus v4).

**Sources of truth:**
- `plans/VLT-REMED/findings/P5.1-review-findings.md` — **SSOT входа P5.2** (10 находок: сценарии отказа,
  готовые векторы инъекции для R-04, рекомендованная диспозиция, группировка швов). **Файл неизменен** —
  диспозиция пишется в Completion Notes P5.2, не в реестр.
- `plans/VLT-REMED/phases/P5.md` — ТЗ P5.2 (16 полей: Scope Included по группам G1–G4, Code Guidance с
  анти-подгонкой и формой каждого регресс-теста, Validation с точными командами).
- `plans/VLT-REMED/plan.md` — §Routing (P5.2 → opus/high) · Decision Log **D1–D30** (несущие: D4 два
  эшелона · **D27** контракт TipTap · **D28** аддитивный конфиг-шов · **D29** запись≠чтение ·
  **D30** удаление `follow_redirects`) · Status Board · Update Log.
- `plans/AUDIT-VLT/findings/REGISTER.md` — 103 находки, append-only, VLT-REMED в него НЕ пишет (D13).
- `plans/VLT-REMED/MORNING-QUEUE.md` §4/§5 — уже принятый долг (не переоткрывать).
- `plans/VLT-REMED/open-questions.md` — Q5 `Resolved → D27`; Q3/Q4 `Decision pending`, P5 не блокируют.

**Open risks:**
- **R-01 (Blocker).** Фикс P0.1 сделал невозможным сохранение документа с `image`/`table`/`underline` —
  узлами, которые пакет САМ объявляет редактору; легаси-контент падает на ЧТЕНИИ вопреки докблоку
  `NodeDocumentVersion::tipTapContent()`. Чинить по КЛАССУ (D27): развести «безопасность схем `href`/`src`»
  (сделано верно — сохранить) и «полнота модели документа» (сломано — чинить). Тест-норма — обход
  `DocumentType::cases()` → `#[TiptapExtensions]`, а не литеральный список узлов.
- **R-02/R-03 (Major, tus/CAS).** Барьер обязан стоять на пути ДОСТАВКИ вреда: `expire()`/`abort()` —
  под тем же локом, что PATCH; `append()` — fail-CLOSED. Регресс-тест — **реальная гонка** (форк на PG,
  образец `tests/Concurrency/TusConcurrentPatchTest.php`), а не «метод вызвался». Класть ТОЛЬКО в
  `packages/drive/tests/Concurrency/` — единственный путь, который исполняет лейн `drive-pgsql`
  (`.github/workflows/drive.yml:135`); иначе тест осиротеет.
- **R-05 (Major).** Гасить ВЕКТОР, а не флаг (D30). Красный тест возможен: Laravel-овский `Http::fake()`
  строит `HandlerStack::create()`, поэтому guzzle-овский redirect-middleware в фейке реально следует 302.
  Если тест зелёный на pre-fix коде — чинить ТЕСТ (реальный сокет), а не объявлять находку закрытой.
- **R-07 (Major).** Второй эшелон D4 сегодня НЕДОСТИЖИМ. D29 делает его достижимым (значения атрибутов
  не вычищаются на чтении — их добивает symfony-санитайзер). Если после фикса эшелон всё равно
  недостижим — **честно признать одноэшелонность** (Known Deviations + правка докблоков), а не
  притворяться.
- **R-06 (Major).** Сверка `UPGRADE.md` идёт ОТ Completion Notes каждого item'а К файлу (греп по F-id
  ничего не найдёт, если секции нет вовсе).
- **Снаги харнесса (унаследованы):** core-тесты гоняются ИЗ КОРНЯ (`vendor/bin/pest --filter=X`),
  сателлиты — `cd packages/<pkg> && vendor/bin/pest <path>`; `packages/core` своих composer-скриптов НЕ
  имеет. PG-прогон — `php8.4` (root-CLI PHP 8.5 без `pdo_pgsql`) + БД `vaulter_test` в контейнере
  `agelto_postgres` (127.0.0.1:5455, роль `vaulter`). Нет coverage-драйвера → MSI локально не измерим.
  `cache.default => 'array'`; фиче-флаги маршрутов — до boot.
- **Пред-существующие флейки (вне scope):** `MassAssignmentHardeningTest` (count-зависимый, только PG) ·
  `NodeCommentSchemaTest` (jsonb key-order, только PG).
- В рабочем дереве живут НЕ относящиеся к VLT-REMED правки (`.claude/`, `plans/ACTIVE.md`,
  `plans/AUDIT-VLT/*`) из конкурентной сессии/хука — НЕ трогать, коммитить только scoped-пути item'а.

**Workarounds/Deferred/Open questions:**
- **workarounds:** core-тесты — из корня, сателлиты — из своей папки · red-before верифицируется откатом
  ТОЛЬКО не-тестовых файлов (`git checkout <base> -- <src>` → прогон → `git checkout HEAD -- <src>`), а НЕ
  `git stash` (в дереве чужие правки) · PG-сценарии — `php8.4` + `agelto_postgres` · провизорный
  MSI-порог 50/70 · `lint:check` красный на `plans/AUDIT-VLT/artifacts/repro/**` — известный долг, гейт
  не блокирует.
- **deferred:** `CHANGELOG.md`-наполнение (deny-правило, D23 — BC ведём в `UPGRADE.md`) · **P4.9 / P4.10** ·
  **F-P4.5-02** (hocuspocus v4) · реальный измеренный MSI (прогон в CI) · остаточный `npm audit high` в
  `packages/vue` · runtime-guard на разрыв connection для 4 моделей `drive`/`documents` (MORNING-QUEUE §5) ·
  архивация AUDIT-VLT (`/task:plan-close archive AUDIT-VLT`).
- **open_questions:** **Q5 — `Resolved → D27`** (контракт TipTap; P5.2 разблокирован). Q3 (ротация
  `signing_secret`) и Q4 (абсолютный wall-deny) — `Decision pending`, P5 не блокируют. Q1/Q2 —
  `Resolved → D1/D10`.
