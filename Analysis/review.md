# Как читать эту ветку

Ветка `analysis/latest-stack-and-runs`. Один функциональный коммит и этот отчёт.

## `ee4007b6` — qualify Laravel 13, PostgreSQL 18 and MySQL 9.7

Два решения в одном коммите, потому что оба живут в `ci.yml` / `weekly-full.yml`,
и промежуточный снимок должен оставаться зелёным для policy-тестов.

Подтвердить:

- Пакеты требуют `illuminate/* ^13.0` и `orchestra/testbench ^11.0`.
  `derive_support()` больше не видит линию 12. Filament и раньше был только на 11.
- `env.PHP_SUPPORT` в обоих workflow совпадает с composer/groups. Это проверяет
  `tests/Repo/WorkflowPolicyTest.py` (`test_support_matrix_follows_composer` и соседи).
- Fast-план `all`: 9 ячеек, не 17. DB-план по-прежнему 18 (9 пакетов × pgsql и mysql).
- Джоб `db` и weekly `engines` поднимают один сервис `db`. Образ — выражение с двумя digest.
  Голый тег в таком выражении policy отвергает (новый кейс в `PinPolicyTest`).
- Serial-джоб — только Postgres, tmpfs на `/var/lib/postgresql`.
- Digest в workflow и в `release/tool-pins.json` один и тот же.
- README, UPGRADE, CHANGELOG и обе страницы compatibility называют ту же платформу.
- `load/` не менялся.

Не подтверждено этим коммитом: зелёный Pest на новых образах. Смотри `Analysis/metrics.md`.
Отложенное — `Analysis/proposals.md`. Туда же дописан проход documents, collaboration и webhooks: три находки, без правки кода.

## Проверки, которые уже сняты

- `python3 tests/Repo/WorkflowPolicyTest.py` — 55 OK.
- `python3 scripts/ci/workflow-policy.py` — OK, 9 workflow.

Повтор имеет смысл только если ревьюер меняет пин, выражение сервиса или composer-границу.
Полный `scripts/t all --full` этим заходом не запускался.
