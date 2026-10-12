---
id: D22
date: 2026-10-05
status: accepted
item: P2.2
items: [P2.2, P2.3]
supersedes: [D21]
superseded_by: null
---
# D22 — владелец отменил дальнейшие review P2.2 + P2.3

**Actor:** владелец репозитория, текущая root Codex-сессия.
**Evidence:** последнее прямое указание владельца:
> больше никаких ревью

SHA-256 дословного сообщения UTF-8 без завершающего LF: `09ed2f9e05e29e01150f600663b13ae6f2e8f965d2d600064cad39f1b6a08f7a`.

## Solution

Никакие новые reviewer sessions, review-run или повторные review не запускаются.
Указание владельца отменяет только обязательство дальнейшего независимого
review и ожидание нового reviewer GREEN из D21. Ранее выполненные review
сохраняют свой настоящий результат: completed attempt-0001 — FINDINGS;
attempt-0002 — INCOMPLETE. Эти результаты не переименовываются в GREEN.

D22 переносит вперёд все остальные решения D21 без изменения: полная исходная
реализация P2.2 + P2.3 принадлежит ещё открытому объединённому P2.2;
последовательная зависимость P2.2 → P2.3, исходные Scope/исключения, оба дословных
архива исходных норм, все продуктовые обязательства, dry-run/apply,
генерация, Code Guidance, проверки всех четырёх пакетов, точные матрицы P1
на изолированном PostgreSQL с 0 failed / 0 skipped, freshness и native closure
checks остаются обязательными. Future work и push/publication не разрешаются.

Текущие P2.2 attempt 5 / run `08750714b29a6d5a7792f308c7e281d3832ed1f76f0f1b29319a02f60f1c124b`, исходный root invocation/route,
подлинная reviewer history и бюджет сохраняются. Активный durable
`artifacts/P2.2/owner-review/request.json` не удаляется, не архивируется вместо
активного файла, не редактируется и не отключается вручную. Новый reviewer
request P2.3 не создаётся.

Исполняемые Validation P2.2 — четыре исходных поведенческих qualification
carriers: `code-guidance`, `packages-pg`, `drive-arch`, `documents-arch`.
Review-зависимый `combined-review` исключён прямым указанием владельца.
P2.3 сохраняет три поведенческих verification carriers: `code-guidance`,
`packages-pg`, `documents-arch`; фактический producer и native freshness/closure
остаются условиями его законного старта и завершения. Review-зависимые
`producer-delivery` / `freshness`, которые требуют authentic reviewer GREEN,
не выдаются за пройденную приёмку и не исполняются с поддельным доказательством.

## Why

Последнее указание владельца имеет приоритет над прежним reviewer selection
и над автоматизацией review. Оно не отменяет реализацию или поведенческие
проверки; все доступные продуктовые проверки завершаются в текущей работе.

## Consequences

Native decision metadata supersession атомарен, поэтому D21 архивируется целиком,
а все его не связанные с review положения явно повторно приняты выше.

Текущий Task runtime пока не предоставляет execution-review request withdrawal
или owner-waiver API. `review-cancel` отменяет только транспорт. Terminal policy
продолжает читать сохранённый request и требовать настоящий current GREEN;
с текущим completed FINDINGS это блокирует closed-green кодом
`OWNER_REVIEW_NOT_GREEN`. D22 не является таким GREEN и не исправляет этот
runtime gap ложным terminal entry. После реальных финальных проверок допустима
только read-only проверка terminal policy и честная фиксация её фактического
результата; facade close с автоматической подготовкой review не вызывается.

До появления поддержанного native withdrawal/waiver capability нельзя объявлять
P2.2/P2.3 native closed-green или lifecycle scope-complete. Неподдержанная отмена
review — точный оставшийся closure blocker, а не новый запрос review/разрешения.
