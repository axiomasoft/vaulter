---
id: D16
date: 2026-10-04
status: accepted
item: P2
items: [P2, P2.1, P2.7]
supersedes: []
superseded_by: null
---
# D16 — OPUS P2.1 разделён: классы core (P2.1) и схема 0.3 с upgrade-миграциями (P2.7) (accepted)

**Actor:** claude-opus-5-5/high (дизайн P2 по поручению владельца, D15).
**Evidence:** OPUS `13-workstreams.md` F2/P2.1; `08-data-model-and-migration.md` §2–§3; код `main` @ `938f0ea`:
`Models\Drive\Storage` — 75 файлов-носителей, `Support\Config::table()` с картой `vaulter.table_names`,
`Schema\PanelTablesBuilder` создаёт таблицы по feature-флагам (строки 1249–1257).

## Solution

1. OPUS P2.1 исполняется двумя items:
   - **P2.1** — переименования и удаления классов core по 03 §3/§6 (FQN-проход, Rector-карта), без изменения имён
     таблиц, колонок и значений событий в БД;
   - **P2.7** — схема 0.3: `SchemaBuilder` создаёт целевые таблицы/колонки (03 §7, 08 §2 в части P2), upgrade-миграции
     №1, №2, №4, №8, №9 (08 §3), перевод ссылок миграций №3/№6/№7 (P1, R3 дизайна P1) на канонические ключи, перевод
     строковых имён таблиц/колонок во всех пакетах и значений `EventType` вместе с миграцией данных событий.
2. Номер P2.7 — новый; OPUS-нумерация P2.1–P2.6 (D01 п.3) не меняется. Единицы OPUS, относящиеся к схеме, назначены
   P2.7 в `artifacts/coverage/ledger-P2.tsv`.
3. Порядок: P2.1 → P2.7 → P2.2 → P2.3 → P2.4 → P2.5 → P2.6.

## Why

Переименование классов — чистый FQN-рефакторинг, проверяемый статикой и существующими тестами; переименование
таблиц/колонок — изменение данных с upgrade-путём на фикстуре P0.3 (PG/MySQL), off-by-one в котором теряет данные.
Один item смешал бы два разных доказательства и не поместился бы в одну сессию исполнителя. Шов чистый: после P2.1
модель `Drive` живёт на таблице `v_storages`, после P2.7 — на `v_drives`; каждое состояние зелёное.

## Consequences

Phase Assurance P2 включает data-migration для P2.7 (frontier/high, full). Колонки, которые 08 §3 относит к
миграциям №5 и №6 (`v_documents`, `v_document_revisions`, `owned_by`, удаление `current_version_id`/`file_id`/
`deleted_at` узлов, `sequence`), остаются за P4.1/P4.7 (R3 дизайна P1).
