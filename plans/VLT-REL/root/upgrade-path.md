# VLT-REL — Upgrade-путь: SemVer-классификация известного потока изменений

> Деливерабл фазы P4 (item P4.1). **Применяет** уже ратифицированное правило бампов при `major=0`
> (`plan.md` D2 → `root/cut-line.md` §4 → `root/contracts/AMEND.md`) к конкретному известному потоку
> изменений — не вводит новых SemVer-классов и не принимает новых решений. Задача: чтобы остаток
> VLT-LOAD/VLT-MUT прилетал ранним потребителям тега `0.1.0` backward-compatible, а не тихим
> breaking-релизом.

**Что этот документ значит.** Карта ожиданий: для каждого известного источника будущего изменения —
какой SemVer-класс он ПОЛУЧИТ по правилу §4, при каком условии класс меняется, и какой гейт ловит
дрейф от плана. Источник классификации — repo-grounded: `cut-line.md` §4 таблица (:123-128) +
`AMEND.md` классификационная таблица (:25-29).

**Что этот документ НЕ значит.** Не sign-off на сами фиксы — содержательные правки L-01…L-08 остаются
dev-срезами вне этого плана (D-LOAD-5, `plans/VLT-LOAD/plan.md:141-148`). Не подтверждение/снятие
гипотез L-02…L-07/F-P2.8-1 (named-фазы VLT-LOAD). Не новое правило бампов и не отмена D11-гейта
(`plans/VLT-LOAD/plan.md:222`) — оба применяются как есть. Не безусловный финальный класс для
неспроектированных фиксов: где дизайна среза ещё нет (L-02…L-07), класс — условный, с явным
переключателем «иначе MINOR».

---

## Ключ классификации

Класс бампа определяется **диффом против P2-снапшота** (`root/contracts/public-api-surface.md` +
per-package `PublicApiSnapshotTest.php` golden), не «на глаз»:

| Дифф против снапшота | Класс | Схема | Основание |
|:--|:--|:--|:--|
| Сигнатуры `Contracts/`/фасада НЕ меняются (внутренняя реализация) | **PATCH** | `0.1.z` | `cut-line.md` §4 :125, `AMEND.md` :29 |
| Аддитивная поверхность (новый контракт/метод/host-facing config-ключ) | **MINOR** | `0.y.0` | `cut-line.md` §4 :126, `AMEND.md` :27 |
| Breaking (удаление/rename/сужение типа) | эскалация §10 + **MINOR+BREAKING** | `0.y.0` + пометка CHANGELOG | `cut-line.md` §4 :127, `AMEND.md` :28, процедура breaking `AMEND.md` :58-68 |

---

## Таблица классификации известного потока изменений

| Источник | Якорь(и) | Ожидаемый класс | Условие-переключатель | Предусловие | Гейт-ловушка дрейфа | Основание (RAG) |
|:--|:--|:--|:--|:--|:--|:--|
| `MetricsRecorder` (VLT-LOAD P8, D31) | `plans/VLT-LOAD/phases/P8.md:16-17` (D31), P8.2 :120-166 (контракт+Null+Fake) | **MINOR** (`0.2.0`), безусловно | новый `Contracts/Observability/MetricsRecorder` — чистый аддитив (D32: не инвертируемый control-flow, Fake-ассерт на каждый seam) | нет (D32: seam аддитивен, **не** фикс горячего пути → D11 к нему не применяется) | core per-package snapshot-гейт (P2.2) — новый контракт попадёт в golden как diff, регенерация только через `AMEND.md` процедуру §«аддитивное изменение» | RAG:— (repo-grounded: `cut-line.md` §4 :126, `AMEND.md` :31-32, `plans/VLT-LOAD/plan.md` D32 :242) |
| L-01 fix (`S3MultipartHandler::complete()` — стриминговая сборка) | `packages/drive/src/Uploads/S3MultipartHandler.php:152-188` (fatal :165), `root/known-limitations.md` L-01 | **PATCH** (`0.1.z`), безусловно | сигнатуры `Contracts/`/фасада не трогаются — фикс внутри реализации за неизменным контрактом | **D11** — dev-срез не открывается, пока `vaulter-drive` не закрыт по VLT-MUT до Score ≥ 95 (`plans/VLT-LOAD/plan.md:222`) | drive per-package snapshot-гейт (P2.2) — красит, если фикс случайно тронет сигнатуру | RAG:— (repo-grounded: `cut-line.md` §4 :125, `root/known-limitations.md` L-01 трекинг) |
| L-02 fix (sweeper batch/chunk-cap) — гипотеза, срез не спроектирован | `packages/drive/src/Console/SweepUploadsCommand.php:37`, `root/known-limitations.md` L-02 | **условный**: PATCH, если фикс не вводит host-facing config-ключ и не трогает сигнатуры `Contracts/`; иначе MINOR | подтверждение гипотезы под нагрузкой — VLT-LOAD **P5**; фактический дизайн среза определит класс | **D11** (drive-файл, тот же барьер, что L-01) | drive per-package snapshot-гейт (P2.2) | RAG:— (repo-grounded: `known-limitations.md` §2 — гипотезы, срезы не открыты) |
| L-03 fix (GC блобов — batch-cap/чекпоинт) — гипотеза | `packages/media/src/Cas/BlobGarbageCollector.php:293-298`, `root/known-limitations.md` L-03 | **условный**: PATCH, если фикс не вводит host-facing config-ключ и не трогает сигнатуры; иначе MINOR | подтверждение — VLT-LOAD **P6/P5** | нет собственного мутационного baseline у `vaulter-media` сегодня (`root/known-limitations.md` §«Почему фиксы не сняты») — D11-аналог не приоритет, но применяется по факту появления | media снапшот — empty-surface гвард (P2.2); если фикс введёт публичный контракт, гвард упадёт первым | RAG:— (repo-grounded: `known-limitations.md` §2, D11-гейт аналогия) |
| L-04 fix (webhook retry — `claimDue()` limit) — гипотеза | `packages/core/src/Webhooks/WebhookRetryDispatcher.php:59-73`, `root/known-limitations.md` L-04 | **условный**: PATCH, если фикс не вводит host-facing config-ключ и не трогает сигнатуры; иначе MINOR | подтверждение — VLT-LOAD **P6** | нет собственного мутационного baseline у `vaulter-core` для этого файла — D11-аналог не приоритет сегодня | core per-package snapshot-гейт (P2.2) | RAG:— (repo-grounded: `known-limitations.md` §2) |
| L-05 fix (outbox relay — `subscribedEndpoints()` O(endpoints)) — гипотеза | `packages/core/src/Webhooks/OutboxRelay.php:100-110`, `root/known-limitations.md` L-05 | **условный**: PATCH, если фикс не вводит host-facing config-ключ и не трогает сигнатуры; иначе MINOR | подтверждение — VLT-LOAD **P3/P6** | нет собственного мутационного baseline у `vaulter-core` для этого файла — D11-аналог не приоритет сегодня | core per-package snapshot-гейт (P2.2) | RAG:— (repo-grounded: `known-limitations.md` §2) |
| L-06 fix (`candidateNodeIds()` N-CTE / `MoveNodeAction` O(subtree)) — гипотеза | `NodeReadRepository.php:454-529` (core), `MoveNodeAction.php:85-100` (drive), `root/known-limitations.md` L-06 | **условный**: PATCH, если фикс не вводит host-facing config-ключ и не трогает сигнатуры; иначе MINOR | подтверждение — VLT-LOAD **P3/P5** | **D11** для доли `MoveNodeAction.php` (drive-файл, тот же барьер, что L-01); core-доля (`NodeReadRepository`) — без D11-аналога сегодня | core+drive per-package snapshot-гейты (P2.2) | RAG:— (repo-grounded: `cut-line.md` §4 :125, `known-limitations.md` L-06 трекинг) |
| L-07 fix (CAS-лок int4-коллизии) — гипотеза | `packages/core/src/Cas/BlobLock.php:35-49`, `root/known-limitations.md` L-07 | **условный**: PATCH, если фикс не вводит host-facing config-ключ и не трогает сигнатуры; иначе MINOR | подтверждение — VLT-LOAD **P5** | нет собственного мутационного baseline у `vaulter-core` для этого файла — D11-аналог не приоритет сегодня | core per-package snapshot-гейт (P2.2) | RAG:— (repo-grounded: `known-limitations.md` §2) |
| L-08 (sweeper/relay/GC — хост не заводит cron) | `SweepUploadsCommand`/`RelayOutboxCommand`/`RetryWebhooksCommand`/`GcBlobsCommand` docblocks, `root/known-limitations.md` L-08 | **без версионного бампа** | не код-фикс — RUNBOOK/докам-задача (хост-долг); версионный поток не затрагивается | нет | нет (документация, не код-поверхность) | RAG:— (repo-grounded: `known-limitations.md` L-08 — факт/хост-долг) |
| F-P2.8-1 (resumable uploads throughput ниже пола SLO-40, тир S) — гипотеза/диагностика | `TusUploadHandler`/`S3MultipartHandler` + `reference-env.md` §1.4, `root/known-limitations.md` F-P2.8-1 | **условный**: PATCH, если диагностированный фикс не вводит host-facing config-ключ и не трогает сигнатуры; иначе MINOR | диагностика боттлнека — VLT-LOAD **P3/P4** (замер P2.8); класс определится, когда появится конкретный дизайн фикса | **D11**, если фикс окажется в drive-файле (`S3MultipartHandler`) — тот же барьер, что L-01 | drive per-package snapshot-гейт (P2.2) | RAG:— (repo-grounded: `known-limitations.md` §2, `cut-line.md` §4 :125) |
| VLT-LOAD P3–P7 (измерительные фазы, обобщающая строка) | `plans/VLT-LOAD/plan.md` §7 (L-02…L-07/F-P2.8-1 трекинг-колонка) | **нет прямого SemVer-эффекта сам по себе** | измерительная фаза подтверждает/снимает гипотезу и МОЖЕТ породить dev-срез — тогда срез классифицируется соответствующей строкой L-* этой таблицы (условный класс до появления дизайна) | зависит от того, какой L-код срез затронет (см. соответствующую строку) | тот же гейт, что у затронутого L-кода (per-package snapshot P2.2) | RAG:— (repo-grounded: `known-limitations.md` §2 трекинг-колонка) |

---

## Связь с `## 7. Contracts` и AMEND

Любая строка этой таблицы, доходящая до MINOR/MINOR+BREAKING, проходит процедуру `AMEND.md`
(аддитив — §«Процедура аддитивного изменения», breaking — §«Процедура breaking-изменения»): golden
регенерируется только через `UPDATE_SNAPSHOT`-гейт из этой процедуры, заводится новый `D#` в
`plan.md` `## 5. Decision Log`, и синхронно обновляется `plan.md` `## 7. Contracts` (строка
`Exported` — Версия + Уведомлены; при потреблении чужого frozen-контракта, как `MetricsRecorder` —
строка `Consumed`). CHANGELOG несёт класс бампа (MINOR/MINOR+BREAKING) по правилу `cut-line.md` §4.

D11-гейт (`plans/VLT-LOAD/plan.md:222`) остаётся предусловием ИСПОЛНЕНИЯ dev-среза для drive-файлов
(L-01, доля L-06, потенциально F-P2.8-1) — эта таблица классифицирует ОЖИДАЕМЫЙ класс бампа, не
снимает и не заменяет D11 как барьер запуска среза.
