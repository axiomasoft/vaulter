# VLT-REMED — Morning Queue

Сформировано автономным прогоном (`vlt-remed-drive` + `vlt-remed-auto`), ночь 10→11.07.2026.
Здесь только то, что **требует человека**. Всё, чего здесь нет, — закрыто и прошло adversarial-ревью.

## Итог прогона

**Закрыто автономно (23 items):**
P0.1–P0.3 · P1.1–P1.4 · P2.1–P2.3 · P3.1–P3.6 · P4.2–P4.8

**Не закрыто (4 items):** P4.1, P4.6*, P4.9, P4.10 — ниже.
`*` P4.6 — код закрыт, Major по нему **дочинен утром** (`4e7fc17`); остаётся только сверка.

**Не запускалось:** P5 (сквозной adversarial code review) — по замыслу, вручную.

---

## 1. P4.1 — 🔴 Blocked (эскалация исполнителя)

**Статус:** 13 из 14 находок закрыты штатно. Item помечен 🔴 **только** из-за инварианта
plan-lint (`Escalation Needed=yes ⇒ Status=🔴`), а не из-за проваленной работы.
Root gate и все 4 satellite-package gates — зелёные.

**Закрыто:** MSI/arch/type/Rector-гейты (root + 4 per-package `infection.json5`/`rector.php`),
`App\*`-запрет + E3.1-граница публичного API во всех 5 `ArchTest.php`, честная CI-матрица
Laravel 12/13 для 4 сателлитов, CVE `firebase/php-jwt` (^7.0), critical RCE `happy-dom` (^20.10.6),
пиннинг образа hocuspocus-server, e2e-healthcheck, TipTap 2.x→3.x (чистый механический бамп).

**❗Требует решения — F-P4.5-02 (hocuspocus 2.x→4.x):**
Бамп ронял регресс-тест `websocket-hardening.test.js` в бесконечный hang — тест опирается на
v2.x-специфичное внутреннее API, это **реальный сдвиг контракта**, а не флак. Бамп реверчен,
диспозиция ⛔ Skipped by decision.
→ Выбор: (а) принять скип, перевести item в 🟠 с задокументированным отклонением; либо
(б) завести отдельный item на нормальную миграцию на hocuspocus v4 (переписать тест под новое API).

**Known Deviations P4.1 (тоже на тебя):**
- **MSI-порог (50/70) провизорный** — реальный прогон Infection недостижим в песочнице
  (нет xdebug/pcov, sudo/pecl заблокированы харнессом). Нужен живой прогон и калибровка порога.
- **Residual `npm audit` high** (vite/esbuild, dev-server-only) в `packages/vue` — блокер апстрима
  `vite-plugin-dts`.

---

## 2. P4.9 / P4.10 — не исполнялись (высокий blast-radius)

Сознательно **не отданы автономному прогону**: канон структуры + ADR (P4.9) и массовый
межпакетный namespace-refactor (P4.10) — необратимые решения с широким радиусом поражения,
их нельзя коммитить ночью без надзора. Плановый routing сам помечает их closed-enum триггером
эскалации (`plan-protocol` §10).

→ Запускать под присмотром: `/task:plan-exec VLT-REMED P4.9`, затем `P4.10`.

---

## 3. CHANGELOG.md недоступен агентам

`.claude/settings.local.json` содержит deny-правило `Read(**/CHANGELOG.md)` — агенты не могут
ни читать, ни писать файл (барьер уже зафиксирован в плане как D23). Следствие: **все BC-записи
ночи ушли только в `UPGRADE.md`, CHANGELOG не обновлялся**.

→ Либо снять deny и прогнать консолидацию CHANGELOG, либо вести его вручную.
Незафиксированные в CHANGELOG BC: события `NodeGrant/NodeShare` (P0.2),
`EnumCaseAttributeResolver` static→instance (P4.6), плюс BC из P4.4/P4.5/P4.7.

---

## 4. Подозрение на системный пробел в UPGRADE.md

При дочинке P4.6 выяснилось: BC-изменение **без собственного F-id** не ловится grep-проверкой
по F-id и может молча не попасть в UPGRADE.md (ровно это и случилось с `EnumCaseAttributeResolver` —
P4.7 его «не увидел» и ошибочно счёл behaviour-preserving).

→ Стоит прогнать сверку `UPGRADE.md` против Known Deviations всех `plans/VLT-REMED/phases/P*.md`:
возможно, есть другие такие же пропуски. Кандидат на `/task:review`.

---

## 5. Мелкий долг (не блокирует, но накопился)

- **Кириллические типы коммитов** — `фикс(core)`, `докс(план)`, `рефакторинг(core,documents)`
  вместо `fix`/`docs`/`refactor`. Ломает Conventional Commits и SemVer-оракул по типам.
  Коммиты: `74294e0`, `baeb5ae`, `e4277a3`, `4bbaa7f`.
- **F-P2.3-02 закрыт частично:** `config('vaulter.connection')` маршрутизирует схему/данные для
  12 моделей `core`, но **не** для 4 моделей-сестёр — `drive` (`UploadSession`, `UploadPart`) и
  `documents` (`NodeComment`, `NodeDocumentVersion`). Их таблицы создаются на именованном
  соединении, а модели остаются на дефолтном. Барьер сейчас — только prose-warning в доке,
  **runtime-guard/fail-fast отсутствует**. Хост монорепо-бандла с `VAULTER_DB_CONNECTION=other`
  получит `table not found`.
  → Завести follow-up item: явный `LogicException` в `boot()` провайдеров drive/documents.
- **Мёртвые якоря в `docs/guide/errors.md`** — систематический пробел: ~12 исключений ссылаются
  на несуществующие секции, и новый `MissingPackageException` (P4.4) расширил его ещё на один случай.
- **Cycle-detection затрагивает авторизацию** (P4.2, `AccessResolver::ancestorChain`): цикл в
  `parent_id` теперь молча усекает CTE. Направление **fail-closed** (меньше allow-грантов, не больше) —
  безопасно, но неявно; стоит упомянуть в UPGRADE/Completion Notes для будущего аудитора.

**Прочие Minor из ревью (зафиксированы в Pending Work соответствующих items, действий не требуют):**
нормализация mime в `DownloadHeaders` (P1.1) · TOCTOU в кап-е upload-сессий (P1.3, плановое
отклонение) · инертные role-гранты у spatie-драйвера без установленного пакета (P2.1) ·
асимметрия `handler()` по trash-гарду (P2.2).

---

## Следующий шаг

1. Разобрать пп. 1–3 (решения твои).
2. Затем P5 — сквозной adversarial code review всей ремедиации, вручную.
