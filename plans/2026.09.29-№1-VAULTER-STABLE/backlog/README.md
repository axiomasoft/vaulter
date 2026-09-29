# Backlog фаз (D03)

Skeleton-носители фаз P1–P8 до их очереди. Не phase carriers: Task tooling их не читает.
`task:plan-design 2026.09.29-№1-VAULTER-STABLE <Pn>` переносит `backlog/Pn.md` в `phases/Pn/Pn.md` и детализирует items по фактическому коду;
затем `finish` → `task:plan-audit … design` → исполнение → фазовый аудит → закрытие (D03).

Дизайн фазы по D06: `python3 artifacts/coverage/opus-coverage.py seed-ledger --phase Pn > artifacts/coverage/ledger-Pn.tsv`,
доводка назначения каждой единицы до конкретного item (или item будущей фазы / `plan:Dnn` с обоснованием в `note`),
`opus-coverage.py render --item Pn.m > artifacts/Pn-design/Pn.m-opus.md` для каждого item, self-check D06 п.6 в индексе
фазы, `opus-coverage.py check` exit 0. Planned Items в `Pn.md` — владельцы секций в `section-owners.tsv`; их ID не меняются.
