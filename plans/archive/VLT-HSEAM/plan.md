> **Архивировано как superseded.** Scope полностью поглощён VLT-CANON и ADR-0018; пункты ниже не исполнять.

# План

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | VLT-HSEAM |
| Title | Editor-agnostic шов файловых обработчиков: закрыть утечки TipTap до 1.0 |
| Document Type | Executable Master Plan |
| Authoring Model | opus (fable) |
| Repository | /home/vostrikov/projects/packages/vaulter (axioma-studio/vaulter) |
| Related Packages | vaulter-core, vaulter-documents, vaulter-drive, vaulter-filament, @vaulter/vue |
| Execution Mode | phase-first |
| Target Operator Models | opus (P0.1–P0.2 — публичные контракты) · sonnet (P0.3–P0.5) |
| Approval Owner | Dmitry Vostrikov |
| Home | repo:plans/VLT-HSEAM |
| Layout | v2 |
<!-- migration-version: 0.1.0 -->

## 1. Context

Владелец запросил «папку-реестр обработчиков файлов»: tiptap — основное, но не единственное
расширение; клиенты встраивают собственные обработчики по каркасу; критерий №1 — отсутствие
breaking changes после релиза. Design-сессия (рекон + RAG по 4 фронтам, `findings/P0-*.md`)
показала: реестр уже существует (`DocumentHandler`/`HandlerRegistry`/`NodeHandlerResolver`,
mime/ext-диспатч, capabilities, priority, fallback `DownloadOnlyHandler`) и совпадает с
дистиллятом WOPI/Nextcloud/Flysystem/Filament; «папка обработчиков» в правильной форме —
behaviour-пакеты монорепо (`packages/documents` = tiptap-расширение). Полная переработка
отклонена (ADR: `root/adr-file-handler-seam.md`). Скоуп плана — 5 резидуальных утечек TipTap
в ядро (нейминг контрактов, exporter-сигнатура, TipTap-валидатор в core, `format` default,
`richText` default), политика эволюции capabilities и DX-каркас для сторонних хендлеров.
Всё — пре-1.0, пока переименования бесплатны по SemVer.

## 2. Execution Rules

- Один item за раз; статус — в плане ДО завершения работы; 6 канонических статусов байт-в-байт.
- Коммит-гейт: item не 🟢/🟠 без закоммиченного диффа; Conventional Commits (рус.), атомарно;
  gate пакета зелёный ДО коммита: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`,
  pest-сьюты через `/usr/bin/php8.4`, type-coverage 100%.
- Публичная поверхность: каждый item P0.1–P0.2 сопровождается прогоном субагента
  `vaulter-api-surface` (diff публичного API против base) — отчёт в Completion Notes.
- Переименования — по дисциплине `php:namespace-refactor-safety`: якорить find-replace на FQN,
  не на голый токен; пакет НЕ зарелижен → фаза alias не нужна, hard rename сразу.
- Ревью: после P0.1, P0.2, P0.4 — `vaulter-reviewer` (ось BC/SemVer, register-vs-boot).
- План diverged от кода → эскалация (§10 протокола), не импровизация.
- **Переходное правило Layout v2 (до P6):** закрытие пункта фиксируется записью в `journal.jsonl`, находкой или решением при необходимости и регенерацией видов; поля исхода в файле ТЗ запрещены и ловятся линтером.

## 3. Routing

| Items | Model/effort · Tool Capability | Why |
|:--|:--|:--|
| P0.1 | opus/high | SemVer-публичные контракты, переименования по всему монорепо |
| P0.2 | opus/high | SemVer-публичные контракты, переименования по всему монорепо |
| P0.5 | sonnet/medium + `vaulter-api-surface`, `vaulter-test-verifier` | финальная сверка поверхности и гейта |

Остальное — по SSOT-матрице (`plan-protocol` §9): package-implementer sonnet/medium.

## 5. Decision Log

Решения находятся в `decisions/`; собрать или проверить их виды: `python3 packages/task/scripts/plan-views.py decisions --plan-dir <plan-dir> [--check]`.
