# 2026.09.29-№1-VAULTER-STABLE — План №1 — Vaulter 1.0: канон, корректность и стабильный API

## 0. Meta

| Field | Meaning |
|:--|:--|
| Plan ID | 2026.09.29-№1-VAULTER-STABLE |
| Short ID | PLAN2 |
| Title | Plan №1 — Vaulter 1.0: канон, корректность и стабильный API |
| Layout | v2 |
| Document Type | Executable Master Plan (катящийся дизайн с немедленным исполнением — D03) |
| Authoring Model | claude-opus-5-5/high (создание плана, дизайн P0, 2026-09-29) |
| Repository | vaulter |
| Related Packages | `packages/{core,media,drive,documents,filament,azgard,vue}`, `hocuspocus-server`, `docs/`, CI, `scripts/`, `tests/` |
| Input | `audits/2026-09-29-stable-audit/opus/` — норматив (D01); исходный аудит и пакет Codex — провенанс |
| Execution Mode | design Pn (`backlog/` → `phases/`) → finish → design audit → execute Pn → phase audit → close → design Pn+1 (D03) |
| Target Operator Classes | per-item class/effort — §3 Routing; расписание — `roadmap.md` `execution-sheet/v1`; пул — `session-routing.md` |
| Approval Owner | repository owner |
| Home | repo:vaulter |
| visibility | private |
| supersedes | — |
| Successor | — |
| Paused By | — |

## 1. Context

Vaulter — монорепо Laravel-пакетов (MIT): файловый Drive, документы (TipTap/Yjs), CAS-медиа, мост AzGuard, опциональный
Filament и клиент `@vaulter/vue`; публикуется split-пакетами `axioma-studio/vaulter-*`. Предыдущие планы (VLT-CANON,
VLT-REMED, VLT-LOAD, …) довели продукт до тега `v0.2.0` с сильными участками (CAS с race-free GC, outbox и подписанные
webhooks, tus/S3 uploads, pluggable авторизация, load/observability стенд).

Финальная проработка аудита 2026-09-29 (OPUS, D01) показывает, что 1.0 выпускать рано: транзакции идут не на то
соединение (N01), колонки ключей хоста типизированы стратегией Vaulter (N02), CAS теряет область дедупликации (E1),
листинг раскрывает запрещённые узлы (N08), дерево не защищено от «папки внутри файла» и гонки циклов (N05), доменные
ошибки дают HTTP 500 (N11), события схлопываются (E8); публичный PHP API не авторизует, словарь конфликтует
(Storage/Panel/Version), конфиг — статический `Support\Config` на 1578 строк, REST без версии и RFC 9457.

**Ключевой факт:** 0.2.0 не опубликован (Packagist/npm 404, потребителей нет — D02). Окно канона 0.3.0 (AD01)
используется полностью: жёсткие переименования без PHP-алиасов, устаревшие ключи конфига — через нормализатор до 1.0,
схема — upgrade-миграцией с сохранением данных.

Цель — **Vaulter 1.0**: сначала корректность T0 (P1), затем канон имён и конфига (P2), авторизованный публичный API
`Vaulter::drive($owner)->actingAs($user)->…` (P3), модель данных и документы (P4), конвейер событий (P5), REST v1 и
клиенты (P6), эксплуатация и DX (P7), release-гейты и заморозка (P8). Карта фаз — `roadmap.md`.

## 2. Execution Rules

- **Норматив — OPUS** (D01). Код расходится с досье → стоп и описание расхождения в Completion Notes/эскалации, не
  импровизация. Решение аудита меняется только новым D# плана.
- **Ничего из OPUS не теряется** (D06, B6): 1438 единиц OPUS в 254 секциях имеют владельцев
  (`artifacts/coverage/section-owners.tsv`); при дизайне фазы каждая её единица назначается item в
  `artifacts/coverage/ledger-Pn.tsv`; гейт `python3 plans/2026.09.29-№1-VAULTER-STABLE/artifacts/coverage/opus-coverage.py check`
  exit 0 — в Validation каждого finish, фазового аудита и закрытия. Отказ от единицы — только D# плана.
  D07 добавляет побайтный manifest `opus-sources.sha256`, проверку свежести дословных входов и негативные контроли;
  ID/назначения не меняются, исходные переносы строк в render сохраняются.
- **Всё перед глазами исполнителя** (D06, B7): первый Required Read каждого item —
  `artifacts/Pn-design/<Pn.m>-opus.md` (дословные единицы OPUS, `opus-coverage.py render`); исполнитель выполняет каждую
  или эскалирует. Контракт/досье содержат все файлы, имена, сигнатуры, решения развилок, точные команды с ожидаемым
  выводом и негативные контроли; «при необходимости», «по образцу» без ссылки, «уточнить при исполнении», «и т.п.»,
  «аналогично» запрещены. Дизайнер записывает self-check D06 п.6 в индекс фазы; design audit проверяет его.
- Запреты OPUS 01 §5 и отложенное AD39 действуют во всех фазах (D06 п.7).
- **Катящийся дизайн** (D03): детализируется только текущая фаза по фактическому коду; фазы после неё — skeleton в
  `backlog/Pn.md`. Цикл фазы: design → finish → независимый design audit → execute → **фазовый аудит** → close.
  Для повторной проверки исправлений текущего дизайна P0 владелец разрешил same-author recheck до GREEN (D08);
  исходный независимый RED сохранён. Это исключение не распространяется на проверку реализации или будущие фазы.
- **Гейт затронутых пакетов до коммита** (OPUS 13 правило 1): `scripts/php84 vendor/bin/pint --test`,
  `scripts/php84 vendor/bin/phpstan analyse` (уровень пакета; max — цель P2.5+), `scripts/php84 vendor/bin/pest` (+ arch);
  для Vue — `vue-tsc`, `vitest`, `vite build`. Пропуск или падение ≠ успех.
- Изменение публичной поверхности → обновлённый `PublicApiSnapshotTest` golden в том же item, запись в `UPGRADE.md` и
  `CHANGELOG.md [Unreleased]` («было → стало», ссылка на `AD##`).
- Переименования — по FQN-якорям (`php:namespace-refactor-safety`), не по голому токену (`Node` ⊂ `NodeGrant`); массовые
  переносы — инструментом по карте (Rector/скрипт), карта коммитится артефактом фазы.
- Нельзя: пропускать/отключать тесты, `|| true` в CI, ослаблять пороги (coverage/type-coverage/mutation/PHPStan), менять
  решения без D#.
- Тесты конкурентности — только PostgreSQL через харнесс P0.2 (D04).
- **Окружение** (D04): PHP 8.4 через `scripts/php84`, PG 16 `127.0.0.1:5432` (`vaulter_test`), MySQL 8 `127.0.0.1:3306`.
  Owner gates O-1 (роль PG), O-2 (пользователь MySQL) — точные команды в D04.
- Код и тесты — только в затронутых `packages/<name>/` и корневых `scripts/`, `tests/`, `docs/`, CI, перечисленных в
  `Files` item; соседние пакеты и чужие (dirty) правки не трогаются.
- Комментарии в коде объясняют «почему», не историю плана; ссылки на работу — только квалифицированные (`PLAN2.D03`,
  `PLAN2.P1.3`) и только когда без них решение непонятно. Внутренние коды задач в docblock'ах не добавляются.
- Документация меняется в том же item, что и код.
- Item закрывается только по своим Validation-командам; «тест есть» ≠ доказательство; новый гейт/харнесс имеет
  негативный контроль (падает на внедрённом нарушении).
- Внешние действия (push, PR, merge, теги, Packagist/npm, split-репозитории) — owner gates (O-3, P8).
  Для P0 D07 уточняет O-3: push итогового candidate и workflow_dispatch — O-3-CI до фазового аудита;
  PR/merge — O-3-PR после GREEN аудита. Оба требуют отдельного явного запроса владельца.
- Canonical statuses: `⬜ Not started`, `🟡 In progress`, `🟢 Done`, `🟠 Done with deviations`, `🔴 Blocked`,
  `⛔ Skipped by decision`. Generated carriers (state, status, bundles, decision views, journal) — только через Task tooling.
- **Маршрут запуска** (D05): класс/effort — §3 Routing; исполнитель/ревьюер — `roadmap.md` `execution-sheet/v1`;
  native launch — `session-routing.md`. Запуск на более слабом классе/effort запрещён; frontier-items не смешиваются в
  батче с implementation/economy.
- **Review `full`** — независимая модель, свежий контекст без reasoning исполнителя; вход — item-spec + diff + вывод
  Validation; строка `review: <provider>/<model> <GREEN|findings:N>` в Completion Notes; ≤2 fix/recheck-раунда, затем
  эскалация. Review `light` — self-check + затронутые тесты той же сессией.

## 3. Routing

Детализирована: P0. Фазы P1–P8 — skeleton в `backlog/` (D03), детализируются по очереди. Batch — предложение группировки;
окончательная — execution-sheet в `roadmap.md`.

| Batch | Items | Model class/effort | Exec | Review | Why |
|:--|:--|:--|:--|:--|:--|
| solo | P0.1 | implementation/medium | manual | light | recommended: окружение (`scripts/php84`, preflight БД) и базовая линия — предусловие остальных items; сначала bootstrap, затем O-1 preflight. |
| B0-A | P0.2 | implementation/medium | manual | full | recommended: харнесс конкурентности — фундамент доказательств T0 (V02, V03, V13, V17, V19–V21); первым в B0-A. |
| B0-A | P0.3 | implementation/medium | manual | full | recommended: фикстура 0.2 на том же окружении PG/MySQL; вторым в B0-A; O-2 до старта. |
| solo | P0.4 | frontier/medium | manual | light | recommended: ADR 0019–0024 — перенос решений OPUS; потребляет baseline P0.1; после него независим от B0-A, frontier не смешивается с implementation. |

## 4. Update Log

| Дата | Изменение | Автор |
|:--|:--|:--|
| 2026-09-29 | План создан по OPUS: D01–D05 (норматив, Q1–Q15, порядок и цикл фаз, окружение, маршрутизация/доставка); P0 детализирована (P0.1–P0.4); P1–P8 — skeleton в `backlog/` | claude-opus-5-5/high |
| 2026-09-29 | Уточнение владельца (B6, B7) → D06: реестр единиц OPUS (1438/254), владельцы секций, ledger P0 (92 единицы), гейт `opus-coverage.py`, дословные входы `artifacts/P0-design/P0.m-opus.md`; P0 дополнена (полный CI в baseline, MySQL-ulid дамп, AD → mAInd, поправки ADR строго по OPUS) | claude-opus-5-5/high |
| 2026-09-29 | По поручению владельца исправлены DA01–DA06 (D07): owner IDs исходной схемы, явный PG-job харнесса, O-3-CI до аудита, bootstrap, дословный render/manifest/негативные контроли, producer baseline P0.4; повторная финализация, без исполнения P0 | gpt-6-sol/high |
| 2026-09-29 | D08: владелец разрешил завершить перепроверку исправлений дизайна P0 до GREEN в текущей sol-сессии; same-author recheck явно обозначен, фазовая приёмка реализации сохранена | gpt-6-sol/high |

## 5. Decision Log

Каталог решений: `decisions/`; собрать: `python3 <task-package>/scripts/plan-views.py decisions --plan-dir <plan-dir>`.
Решения аудита — `AD01…AD40` (OPUS `02-decisions.md`), не копируются (D01).

## 7. Contracts

### Exported

| Contract | Version | Status | Consumers | Notified |
|:--|:--|:--|:--|:--|
| Публичный PHP API (`Vaulter::drive()` handle, контракты OPUS 05/06) | P3 | draft | хосты, авторы расширений | — |
| REST v1 + OpenAPI `openapi/vaulter-v1.yaml` | P6 | draft | `@vaulter/vue`, хаб, хосты | — |

### Consumed

| Contract | Donor plan | Pinned | Spotted | Reaction |
|:--|:--|:--|:--|:--|
| Кандидат `v0.2.0` | vaulter (архивные планы) | `v0.2.0` | 2026-09-29 | Исходная точка; BC PHP не обязательна (AD01), данные и конфиг 0.2 мигрируют. |
| Процесс Task layout v2, катящийся дизайн | `corex:PLAN3` | 2026-09-29 | 2026-09-29 | Принят (B4, D03). |
| AzGuard (тегированный релиз) | azguard | — | 2026-09-29 | `vaulter-azgard` выходит только при теге (D02, O-4). |
