# P2.6 — квалификационный прогон P2 (D31: без полной матрицы)

Статус: **V1–V3, V5–V7 зелёные; V4 — exit 1 из-за внешнего изменения azguard (не регрессия P2), см. ниже.**
База: `35718fd2`; продукт (`packages/*/src|database|config|routes|composer.json`) неизменён. Машина: локальная, engine sqlite.
Логи: `var/quality/qualification/P2/<check>.log`.

**Полная матрица `scripts/t all --full --engines=sqlite,pgsql,mysql` — при закрытии плана (D31, P1.9).** Серверные линии не гонялись: P2 не менял schema/query/transaction/locking.

| Check | Команда | Engine | Результат | Время |
|---|---|---|---|---|
| V1 | `git diff --quiet 35718fd2 -- packages/*/…` + пустой `git status` | — | exit 0 | <1 с |
| V2 | `scripts/php84 php scripts/inventory/inventory.php --check` | — | exit 0 (`[target-map] ok`) | 12 с |
| V3 | `scripts/t sqlite -p repo` | sqlite | exit 0, 181 passed, skipped 0 | 16 с |
| V4 | `scripts/t sqlite` | sqlite | **exit 1**: 49 партиций ok, 2 FAIL; skipped=28 (внутри партиций, штатные) | 5 м 0 с |
| baseline | `scripts/t baseline --write -p repo` | — | exit 0; repo: original 0 → current 181 | — |
| V5 | `scripts/t baseline --compare` | — | exit 0, missing 0 по всем наборам; added: repo 181 | — |
| V6 | pint `--test scripts/inventory tests/Repo/Arch` + phpstan level 6 `scripts/inventory` | — | exit 0, errors 0 | — |
| V7 | `test -s …/qualification.md` | — | exit 0 | — |

## V4: падения (не регрессия P2)
- `azgard sqlite [InteractsWithAzGuard+IsolatedRealAzgardTestCase+RefreshDatabase]` и `[…RealAzgardTestCase…]`, exit=2:
  `Call to undefined method AzGuard\Panels\PanelBuilder::scopes()` (`packages/azgard/tests/RealAzgardIsolated/Stubs/IsolatedDocumentsPanel.php:33`).
- Причина: внешний репозиторий `../azguard` (HEAD `902ada79`, 2026-10-14, PLAN1.P3.14) убрал `scopes()`. `git diff 35718fd2 -- packages/azgard` пуст → код и тесты azgard те же, что на базе; падение определяется внешней зависимостью. Тот же класс проблемы — в P1.9 (azgard phpstan).
- Пункт остаётся pending: продукт не чиню (scope exclude `packages/**`). Worktree-прогон на 35718fd2 не делался: результат зависит от `../azguard`, а не от содержимого этого репо. Повтор не нужен: причина детерминирована (undefined method), не таймаут.

## Прочее
- Baseline: `tests/baseline/repo.{list,count}` и `CHANGES.{json,md}` обновлены (+181 тест repo suite; добавления, не потери).
- Остальные партиции sqlite (core 20, documents 16, drive 4, media 4, filament 3, repo 1, azgard 3 из 5) — ok.
