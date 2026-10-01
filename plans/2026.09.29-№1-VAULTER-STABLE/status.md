<!-- plan-projection
projection: status-view
projection_version: v1
source_scope: plan core + phases + journal
through: 2026-10-01
inputs_sha256: c9d9afafc8751d8c2ce1ea9914e676f7f28d9c7dd5fcc785654346ec789449c9
generated_by: task plan-views status
-->

# Plan status

> Do not manipulate by hand: this is a generated view.

## 0. Meta

| Field | Meaning |
|:--|:--|
| Version | — |
| Status | 🟡 In progress |
| Last Updated | 2026-10-01 |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/total | Status |
|:--|:--|:--|:--|
| P0 | Основа: окружение, базовая линия, харнесс конкурентности, фикстура 0.2, ADR | 1/4 | 🟡 In progress |

## Phase P0

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P0.1 | Окружение и базовая линия | 🟠 Done with deviations | 2026-09-29 |
| P0.2 | Харнесс конкурентности PostgreSQL | 🟢 Done | 2026-10-01 |
| P0.3 | Фикстура данных 0.2 | ⬜ Not started | — |
| P0.4 | ADR 0019–0024 | ⬜ Not started | — |

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

## Owner Gates

- P0.1: known deviation
