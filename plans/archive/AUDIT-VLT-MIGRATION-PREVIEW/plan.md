> **Архивный migration-preview.** Канонический AUDIT-VLT завершён и хранится в `plans/archive/AUDIT-VLT/`; этот carrier не исполнять.

# План

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | AUDIT-VLT |
| Title | Аудит пакета vaulter: безопасность, встраиваемость, гибкость, качество |
| Document Type | Executable Master Plan |
| Authoring Model | opus |
| Repository | /home/vostrikov/projects/packages/vaulter (axioma-studio/vaulter) |
| Related Packages | vaulter-core, vaulter-drive, vaulter-media, vaulter-documents, vaulter-filament, vaulter-permissions-azgard, @vaulter/vue, hocuspocus-server |
| Execution Mode | phase-first |
| Target Operator Models | sonnet (exec) · opus (P0.2 RAG, P5 синтез) |
| Approval Owner | Dmitry Vostrikov |
| Home | repo:plans/AUDIT-VLT |
| Paused By | — |
| Layout | v2 |
<!-- migration-version: 0.1.0 -->

## 1. Context

vaulter — встраиваемое документохранилище для Laravel (монорепо, 7 пакетов, ~543 PHP-файла):
единое дерево узлов (`Node`), CAS-блобы с дедупликацией и GC, resumable-загрузки (tus + S3
multipart), realtime-collab (Yjs/Hocuspocus), исходящие webhook через transactional outbox,
две канала шэринга (grant-ACL + публичные share-link), Filament-панель, Vue-фронтенд,
внешние ролевые провайдеры (Gate / Spatie / Database / AzGuard) за швом `HostRoleDriver`.
Ядро авторизации — не-swappable `Vaulter\Access\AccessResolver` (алгоритм H.2: host-override →
ограниченный обход предков → allow-маска | deny-маска → роль → финальное deny-вето).

Пакет прошёл несколько итераций разработки (M0–M4b), но **сплошного аудита не проходил**.
Цель этого плана — независимый read-only аудит по четырём осям: (1) безопасность,
(2) встраиваемость и DX, (3) гибкость/расширяемость, (4) качество и производительность —
с выдачей ранжированного реестра находок и ремедиационного бэклога.

Аудит **не чинит** код: правки продакшн-кода запрещены во всех фазах (D2). Результат P5 —
сводный отчёт + бэклог + решение владельца, запускать ли отдельный план ремедиации.

Внешние нормы, на которых стоит чеклист, верифицированы RAG на этапе проектирования и
лежат в `findings/P0-*.md` — исполнитель читает их, а не восстанавливает контекст заново.

## 2. Execution Rules

- Один item за раз; статус обновляется В ПЛАНЕ до завершения работы.
- Статусы — 6 канонических строк байт-в-байт (`plan-protocol` §6).
- **Read-only (D2).** Изменять файлы вне `plans/AUDIT-VLT/` ЗАПРЕЩЕНО. Пруф — чистый
  `git status` по `packages/`, `docs/`, `e2e/`, `hocuspocus-server/`.
- Repro-скрипты и временные тесты — только в `plans/AUDIT-VLT/artifacts/repro/<Pn.m>/`.
  Класть их в `packages/*/tests/` запрещено.
- Каждый audit-item закрывается ДВУМЯ артефактами: `findings/<Pn.m>-<slug>.md` по схеме
  `artifacts/finding-template.md` + строки в `findings/REGISTER.md` (append).
- **Чеклист обязателен (D3).** У каждого audit-item'а закрытый список проверок `C1..Cn`.
  Вердикт по каждой — `✅ pass` (с `file:line`), `❌ fail` (с находкой), `⚠ partial`, `n/a`
  (с причиной). Формулировки «выглядит корректно» / «проблем не нашёл» — дефект исполнения.
- **Сверка с ADR (D5).** До заведения находки прочитать соответствующий `docs/adr/*.md`.
  Расхождение с сознательным решением ADR — находка класса «ADR устарел», не «баг».
- Коммит-гейт закрытия: `git add plans/AUDIT-VLT && git commit` — Conventional Commits,
  тип `docs(plan)` или `chore(audit)`; продакшн-код в коммите отсутствует.
- Найден Blocker → item всё равно закрывается 🟢 (аудит зафиксировал факт), но строка
  Blocker'а обязана попасть в REGISTER и в `handoff.md` → Open risks.
- План разошёлся с кодом (файла из Required Reads нет) → не импровизировать, эскалировать
  (`plan-protocol` §10) и записать в Known Deviations.
- После закрытия item/фазы — перезаписать `plans/AUDIT-VLT/handoff.md`.
- **Переходное правило Layout v2 (до P6):** закрытие пункта фиксируется записью в `journal.jsonl`, находкой или решением при необходимости и регенерацией видов; поля исхода в файле ТЗ запрещены и ловятся линтером.

## 3. Routing

Отклонения от SSOT-матрицы (`plan-protocol` §9):

| Items | Model/effort И/ИЛИ Tool Capability | Почему |
|:--|:--|:--|
| P0.2 | opus/high + `context7` / `perplexity-web` (RAG) | Верификация внешних, версионно-привязанных фактов; дешёвая модель галлюцинирует версии API и статусы CVE. |
| P1.1 | opus/high | Инвариантные рассуждения: свёртка ACL с deny-вето и алгоритмическая корректность JWT/webhook-шва не выводятся чтением по чеклисту без гипотез об обходе. |
| P1.6 | opus/high | Инвариантные рассуждения: свёртка ACL с deny-вето и алгоритмическая корректность JWT/webhook-шва не выводятся чтением по чеклисту без гипотез об обходе. |
| P0.1 | sonnet/medium (дефолт) | Чеклист закрыт и выведен из `findings/P0-*.md`; работа = сверка кода с нормой + `file:line`. |
| P0.3 | sonnet/medium (дефолт) | Чеклист закрыт и выведен из `findings/P0-*.md`; работа = сверка кода с нормой + `file:line`. |
| P1.2–P1.5 | sonnet/medium (дефолт) | Чеклист закрыт и выведен из `findings/P0-*.md`; работа = сверка кода с нормой + `file:line`. |
| P1.7 | sonnet/medium (дефолт) | Чеклист закрыт и выведен из `findings/P0-*.md`; работа = сверка кода с нормой + `file:line`. |
| P1.8 | sonnet/medium (дефолт) | Чеклист закрыт и выведен из `findings/P0-*.md`; работа = сверка кода с нормой + `file:line`. |
| P5.1–P5.3 | opus/high | Ранжирование по риску, синтез и решение о ремедиации — не механическая работа. |
| P1.4 | + `WebFetch` (опционально) | Для сверки актуальных CVE/спеки, если чеклист даёт `⚠ partial`. |
| P1.5 | + `WebFetch` (опционально) | Для сверки актуальных CVE/спеки, если чеклист даёт `⚠ partial`. |
| P1.7 | + `WebFetch` (опционально) | Для сверки актуальных CVE/спеки, если чеклист даёт `⚠ partial`. |

## 5. Decision Log

Решения находятся в `decisions/`; собрать или проверить их виды: `python3 packages/task/scripts/plan-views.py decisions --plan-dir <plan-dir> [--check]`.
