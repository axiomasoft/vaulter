# P3.19 — квалификационный прогон P3 (D31: без полной матрицы)

| Check | Команда | Engine | Время | Exit |
| --- | --- | --- | --- | --- |
| V1 | `scripts/t sqlite` | sqlite | 322s | 0 |
| V2 | `scripts/t baseline --compare` | — | 9s | 0 |
| V3 | inventory.php --check | — | 21s | 0 |
| V4 | `scripts/t sqlite -p repo` | sqlite | 26s | 0 |
| V5 | `scripts/t pgsql -p core --group=database` | pgsql | 62s | 0 |
| V6 | `scripts/t pgsql -p drive --group=database` | pgsql | 14s | 0 |
| V7 | `scripts/t pgsql -p documents --group=database` | pgsql | 24s | 0 |
| V8 | core serial Concurrency | pgsql | 56s | 0 |
| V9 | schema-snapshot compare pgsql+sqlite | both | 86s | 0 |
| V10 | pint --test + phpstan, 6 сьютов | — | 240s | 0 |
| V11 | vue typecheck + api:check + build | — | 24s | 0 |
| V12 | check-doc-links (docs, README, mirror) | — | 0s | 0 |

## Честно о пропусках / предупреждениях
- Database-free партиции на pgsql пропущены by design (`skipped … nothing engine-specific`); отдельные партиции — skipped=1 (V1, V5, V7).
- V2: warn `tests/baseline/repo.list` и `vue.list` stale vs discovery (vue: +4 added, 0 missing); exit 0, baseline не переписывался.
- V10 первый запуск через раннер упал за 0s из-за раскрытия `$root` во внешней оболочке (артефакт запуска, `/scripts/php84`); повторён дословно — rc=0.
- Не запускались (вне scope, D31/D3/D4): полная матрица sqlite,pgsql,mysql (P7), мутации, load/k6, coverage. MySQL-линии не гонялись.
- Существующих падений нет; сравнение с 83b7169e не потребовалось.
