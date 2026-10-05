<!-- plan-projection
projection: status-view
projection_version: v1
source_scope: plan core + phases + journal
through: 2026-10-05
inputs_sha256: 4acac9cf2f513929cbe630d2a56dc7d270beda366c4196d82029aae8e8f82b4c
generated_by: task plan-views status
-->

# Plan status

> Do not manipulate by hand: this is a generated view.

## 0. Meta

| Field | Meaning |
|:--|:--|
| Version | — |
| Status | 🟡 In progress |
| Last Updated | 2026-10-05 |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/total | Status |
|:--|:--|:--|:--|
| P0 | Основа: окружение, базовая линия, харнесс конкурентности, фикстура 0.2, ADR | 3/4 | 🟠 Done with deviations |
| P1 | Исправления T0 на текущих именах | 10/11 | 🟠 Done with deviations |
| P2 | Канон имён и конфигурации | 2/7 | 🟡 In progress |

## Phase P0

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P0.1 | Окружение и базовая линия | 🟠 Done with deviations | 2026-09-29 |
| P0.2 | Харнесс конкурентности PostgreSQL | 🟢 Done | 2026-10-03 |
| P0.3 | Фикстура данных 0.2 | 🟢 Done | 2026-10-02 |
| P0.4 | ADR 0019–0024 | 🟢 Done | 2026-10-02 |

## Phase P1

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P1.1 | Соединение Vaulter для транзакций и блокировок | 🟢 Done | 2026-10-03 |
| P1.2 | Инварианты дерева | 🟢 Done | 2026-10-04 |
| P1.3 | Точная видимость листинга и поиска | 🟢 Done | 2026-10-04 |
| P1.4 | Идентичность событий в outbox | 🟢 Done | 2026-10-03 |
| P1.5 | Беспотерьная область CAS | 🟢 Done | 2026-10-04 |
| P1.6 | Атомарная квота и счётчик ревизий | 🟢 Done | 2026-10-04 |
| P1.7 | Типы колонок ключей хоста | 🟠 Done with deviations | 2026-10-03 |
| P1.8 | Нет HTTP 500 для доменных ошибок | 🟢 Done | 2026-10-03 |
| P1.9 | Стеки middleware и отказ от глобальных биндингов | 🟢 Done | 2026-10-03 |
| P1.10 | Честные зависимости и авторизация Filament | 🟢 Done | 2026-10-03 |
| P1.11 | Метаданные и URL | 🟢 Done | 2026-10-03 |

## Phase P2

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P2.1 | Переименования классов core | 🟢 Done | 2026-10-05 |
| P2.2 | Drive/documents combined canon and final validation | 🟢 Done | 2026-10-05 |
| P2.3 | Verification of documents within the combined workset | ⬜ Not started | — |
| P2.4 | AzGuard из core в vaulter-azgard; media и filament | ⬜ Not started | — |
| P2.5 | Канонический конфиг и типизированный доступ | ⬜ Not started | — |
| P2.6 | Типы новых и переименованных колонок | ⬜ Not started | — |
| P2.7 | Схема 0.3 и upgrade-миграции №1, №2, №4, №8, №9 | 🟠 Done with deviations | 2026-10-04 |

## 6. Update Log

| Date | Who (role/model) | What |
|:--|:--|:--|
| 2026-09-29 | claude-opus-5-5/high | plan-design · no-op · Plan created from OPUS audit (D01–D06); P0 detailed, P1–P8 skeletons in backlog/. Journal seed missing at creation, recorded during P0 finalize-design. |
| 2026-09-29 | claude-opus-5-5/high | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-29 | claude-opus-5-5/high | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-29 | gpt-6-sol/high | plan-audit · in-progress · Owner explicitly authorized current gpt-6-sol/high: «тогда продолжай sol». Independent design audit of finished P0; product remains read-only. |
| 2026-09-29 | gpt-6-sol/high | plan-audit · audit-red · RED: DA01–DA05 Major, DA06 Minor. See findings/design-audit.md. Owner authorized sol/high; next is manual owning design repair, then P0 finalization/finish and new design audit. No product writes. |
| 2026-09-29 | gpt-6-sol/high | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-29 | gpt-6-sol/high | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-29 | gpt-6-sol/high | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-29 | gpt-6-sol/high | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-29 | gpt-6-sol/high | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-29 | gpt-6-sol/high | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-29 | gpt-6-sol/high | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-29 | gpt-6-sol/high | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-29 | gpt-6-sol/high | plan-audit · in-progress · D08: owner explicitly authorized same-author bounded recheck DA01–DA06 to GREEN in this session; reuse original independent audit for unchanged scope. sol/high per owner. Not an independent re-audit. |
| 2026-09-29 | gpt-6-sol/high | plan-audit · audit-green · GREEN: DA01–DA06 closed; bounded same-author recheck explicitly authorized by owner D08. 6 unittest OK, coverage/lint/views/preimages pass. Original independent RED retained. See findings/design-recheck.md; no product acceptance implied. |
| 2026-09-29 | codex/gpt-6-sol | plan-run · in-progress · — |
| 2026-09-29 | gpt-6-sol/medium | plan-run · blocked · PHP 8.4.26/extensions, bash syntax and negative controls OK. PG auth failed, exit 2; O-1 required (D04). Scripts/CONTRIBUTING prepared; baseline not run. Evidence: artifacts/P0.1/preflight.log. Возобновление: PG preflight exit 0, same P0.1 command. |
| 2026-09-29 | gpt-6-sol/medium | plan-run · in-progress · Возобновление по owner: сделал, подолжай. O-1 снят: PG preflight exit 0, PostgreSQL 16.15 vaulter_test. Только локальный baseline; owner запретил hosted CI. |
| 2026-09-29 | gpt-6-sol/medium | plan-run · blocked · Код/95 завершённых команд сохранены. По resource steering агента остановлены 4 дорогих mutation trees (actual -15); их verdict отсутствует. P0.1 не GREEN; требуется отдельный бюджет для полного baseline. Hosted CI не запускался. |
| 2026-09-29 | gpt-6-sol/medium | plan-run · in-progress · Owner: «ну ты доделывай, но без тяжелых перепроверок». Завершение по сохранённой evidence; неполные mutation lanes — явное принятое ограничение, без полного score и без hosted CI. |
| 2026-09-29 | gpt-6-sol/medium | plan-run · closed-deviations · Готовы скрипты, CONTRIBUTING и classified baseline (95 команд + 4 interrupted). Owner принял завершение без heavy rechecks; scores неизвестны. Лёгкие критерии GREEN. write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-29 | gpt-6-sol/medium | plan-run · closed-deviations · Уточнение метаданных закрытия: P0.1 завершён; 4 mutation verdict неизвестны, ограничение принято владельцем (owner-close.log). Код и классификация проверены без heavy runs.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-29 | gpt-6-sol/medium | plan-design · no-op · Bounded metadata refresh after P0.1: resolvable P0.3 references only; original tag commands/scope/OPUS unchanged. plan-lint exit 0. Evidence: findings/P0.1-metadata-repair.md. |
| 2026-09-29 | gpt-6-sol/medium | plan-audit · audit-green · Bounded review of two metadata references and closure fields; original GREEN semantic design retained, no new feature design. plan-lint exit 0; tag commands unchanged. Evidence: findings/P0.1-metadata-repair.md. |
| 2026-10-01 | claude-code | plan-run · in-progress · — |
| 2026-10-01 | claude-opus-5-5/high | plan-run · closed-green · ForkRace harness: PG 4 passed ×5, SQLite 4 skipped, sequential negative control red, root PG lane = baseline, CI step added. Pending outside item: hosted CI O-3-CI, independent review. Evidence: artifacts/P0.2/completion.md |
| 2026-10-01 | claude-opus-5-5/high | plan-run · decision-pending · note abbreviated; full text: journal.jsonl:27 |
| 2026-10-01 | claude-opus-5-5/high | plan-run · in-progress · — |
| 2026-10-01 | claude-opus-5-5/high | plan-run · decision-pending · PG dumps (bigint, ulid, preflight-red) generated, verified, deterministic. MySQL blocked: v0.2.0 migrations fail on MySQL 8 (1215, stored generated column over CASCADE FK). Owner decision needed. Evidence: artifacts/P0.3/completion.md |
| 2026-10-02 | claude-opus-5-5/high | plan-run · no-op · B0-A review GREEN (findings/B0-A-review.md, D09); R6/R8 fixed and re-validated: PG 4 passed x5, SQLite skipped, negative control red. E22 = local CI step; hosted run stays phase gate O-3-CI.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-02 | claude-opus-5-5/high | plan-run · closed-green · PG 0.2 fixture (bigint, ulid, preflight-red): --verify OK, deterministic, all §4 shapes; MySQL refused by D09 (v0.2.0 not installable on MySQL 8). B0-A review GREEN. Evidence: artifacts/P0.3/completion.md.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-02 | claude-code | plan-run · in-progress · — |
| 2026-10-02 | claude-opus-5-5/medium | plan-run · closed-green · ADR 0019–0024 proposed, amendments 0009/0018, index +6; 40 AD records in mAInd project:vaulter; docs build green. Evidence: artifacts/P0.4/completion.md.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-02 | codex/gpt-6.1-sol/high | plan-audit · in-progress · D11: one independent read-only P0 audit; R-P0-1…4 only. Accepted P0.1 mutation deviation excluded. Current route verified from provider turn context. |
| 2026-10-02 | codex/gpt-6.1-sol/high | plan-audit · audit-attention · ATTENTION PA01 (Major): no-barrier mutant survives 4 tests; return P0.2. Fixture/ADR/baseline checks have no finding. Product bytes unchanged. D11 mutation deviation retained. See findings/P0-phase-audit.md. |
| 2026-10-02 | codex/gpt-6.1-sol/high | plan-run · in-progress · — |
| 2026-10-02 | codex/gpt-6.1-sol/high | plan-run · decision-pending · PA01 strengthened; serial PG 5/5 GREEN, barrier-only/sequential RED, SQLite/Pint OK. E23 unavailable: Claude quota resets 13:20 Volgograd. Saved waiting-external checkpoint; no terminal GREEN. See artifacts/P0.2-PA01/completion.md. |
| 2026-10-02 | claude-code/claude-opus-5-5/high | plan-design · no-op · Design finalized; execution has not started. |
| 2026-10-03 | claude-code/claude-sonnet-5-5/xhigh | plan-run · closed-green · PA01 repaired: PG 5/5, mutants RED, SQLite/Pint OK; E23 review GREEN (gpt-6.1-sol, same-model D13). Hosted O-3-CI on changed candidate stays phase gate. Evidence: artifacts/P0.2/completion.md.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | codex/gpt-6 | plan-run · in-progress · — |
| 2026-10-03 | codex/gpt-6.1-sol/high | plan-run · closed-green · Database/model routing, package gates, V17 and negative controls GREEN. See artifacts/P1.1/completion.md. review: grok/grok-4.7 GREEN
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | codex | plan-run · in-progress · — |
| 2026-10-03 | codex/gpt-6.1-sol/high | plan-run · closed-green · V24 20 passed, negative controls and 15 package gates GREEN. See artifacts/P1.8/completion.md. review: grok/grok-4.7 GREEN
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | codex/gpt-6.1-sol | plan-run · in-progress · — |
| 2026-10-03 | codex/gpt-6.1-sol | plan-run · closed-green · V06 3 passed; 9 package gates GREEN. See artifacts/P1.4/completion.md. review: grok/grok-4.7 GREEN
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | codex/gpt-6.1-sol | plan-run · in-progress · — |
| 2026-10-03 | codex/gpt-6.1-sol/high | plan-run · closed-green · P1.9 acceptance GREEN. See artifacts/P1.9/completion.md. review: grok/grok-4.7 GREEN
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | codex/gpt-6.1-sol | plan-run · in-progress · — |
| 2026-10-03 | codex/gpt-6.1-sol/high | plan-run · closed-green · P1.10 acceptance GREEN. See artifacts/P1.10/completion.md. review: grok/grok-4.7 GREEN
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | codex/gpt-6.1-sol | plan-run · in-progress · — |
| 2026-10-03 | codex/gpt-6.1-sol/high | plan-run · closed-green · P1.11 acceptance GREEN. See artifacts/P1.11/completion.md. review: grok/grok-4.7 GREEN
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | codex | plan-run · in-progress · — |
| 2026-10-03 | codex/gpt-6.1-sol/high | plan-run · closed-deviations · P1.7 implemented and validated; known MySQL fresh-install baseline deviation retained for PLAN2.P8.1. See artifacts/P1.7/completion.md. review: grok/grok-4.7 GREEN
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | claude-code | plan-run · in-progress · — |
| 2026-10-04 | claude-code/claude-opus-5-5/high | plan-run · closed-green · P1.2 green under a global lock matrix; review grok-4.7 not GREEN: 11 findings fixed, 2 quota findings deferred to PLAN2.P1.6. See artifacts/P1.2/completion.md.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-04 | codex/gpt-6 | plan-run · in-progress · — |
| 2026-10-04 | codex/gpt-6 | plan-run · closed-green · Exact View listings, scan cursors, batch ACL/abilities and Filament; owner approved Vue fix. All gates GREEN; review: cursor/grok-4.7 GREEN (2 fixed, 1 recheck; 500k unattested). artifacts/P1.3/completion.md. |
| 2026-10-04 | codex/gpt-6 | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-04 | executor/codex | plan-run · in-progress · — |
| 2026-10-04 | codex/gpt-6.1-sol/high | plan-run · closed-green · Lossless BlobScope and scope_key, legacy-preserving migration, repair command and V01/V13/V14 delivered. 12 validation carriers, negative control and whole-work Grok review GREEN. See artifacts/P1.5/completion.md.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-04 | codex | plan-run · in-progress · — |
| 2026-10-04 | codex/gpt-6.1-sol/high | plan-run · closed-green · note abbreviated; full text: journal.jsonl:62 |
| 2026-10-04 | claude-opus-5-5/high | plan-design · no-op · Design finalized; execution has not started. |
| 2026-10-04 | claude-opus-5-5/high | plan-design · no-op · Design finalized; execution has not started. |
| 2026-10-04 | claude-haiku-4-5/lean | plan-close · closed-deviations · Phase P1 closed: 10/11 GREEN items, P1.7 with classified MySQL deviation. Local validation gates: core pint/phpstan OK, coverage OK. Direct close without hosted CI (owner decision). All deliverables integrated. |
| 2026-10-04 | claude-opus-5-5/high | plan-design · no-op · Design finalized; execution has not started. |
| 2026-10-04 | codex/current-session | plan-run · in-progress · Owner-directed P2.1 under D17; reconciling implementation and validation already performed. No design audit or independent review launched. Details: artifacts/P2.1/completion.md. |
| 2026-10-04 | codex/current-session | plan-run · in-progress · Reconciliation run identity recorded for completed owner-directed P2.1. Validation evidence: artifacts/P2.1/validation-final.json. No route/model admission is asserted. |
| 2026-10-04 | codex/current-session | plan-run · closed-green · P2.1 implemented; all 6 PHP package gates GREEN; PG 203 passed, no skips; negative control verified. No audit/review per D17. Azgard qualified on P0 dependency. Details: artifacts/P2.1/completion.md. Changes uncommitted; next P2.7. |
| 2026-10-04 | codex | plan-run · in-progress · — |
| 2026-10-04 | codex/gpt-6.1-sol/high | plan-run · closed-deviations · P2.7 complete. D18: actual migration order. D19: no new audits; Grok FINDINGS fixed, targeted checks green. MySQL baseline: P8.1. Evidence: artifacts/P2.7/completion.md.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-05 | codex/gpt-6.1-sol | plan-run · in-progress · — |
| 2026-10-05 | codex/gpt-6.1-sol | plan-run · decision-pending · P2.2 methods contradict deferred core interface. See findings/P2.2-handle-contract-conflict.md; awaiting owner choice. No product mutation. |
| 2026-10-05 | codex/gpt-6.1-sol | plan-run · decision-pending · decision queue: Q-P2.2-handle-contract-v1 |
| 2026-10-05 | owner | plan-run · in-progress · decision queue: Q-P2.2-handle-contract-v1 |
| 2026-10-05 | codex/gpt-6.1-sol | plan-run · in-progress · D20 amended scope admitted; append-only recovery after queue administrative start with null run_id. |
| 2026-10-05 | codex/gpt-6.1-sol | plan-run · local-repair · Present-only metadata/projection repair; historical closure unchanged. Fresh current checks qualified in artifacts/P2.2/p21-present-metadata-repair-20261005T060658881213Z.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-05 | codex/gpt-6.1-sol | plan-run · closed-green · Owner ordered completion. Qualification E13-E16 green. Independent review attempt-0003 GREEN for candidate 113288b01c2bf7f19943adc84c238af7936f4cb3f5357c494b834f52fe8be601. Prior FINDINGS and incomplete transport retained.
write-site: pre_mutation: allow · pre_final: allow |

## Owner Gates

- P0.1: known deviation
- P1.2: known deviation
- P1.7: known deviation
- P2.7: known deviation
