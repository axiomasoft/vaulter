# P1.9 — квалификационный прогон P1: ЧАСТИЧНЫЙ отчёт

Статус: **не завершён**. Полная матрица остановлена владельцем через ~1ч55м (машина под чужой нагрузкой,
load average 12–18 на 32 ядрах). Полный прогон перенесён на закрытие плана (см. tests/README.md, Run policy).
Product/harness commit: `4f91837d` (рабочее дерево чистое от продуктовых правок; изменены только README/Makefile/план).

## Что выполнено
| Проверка | Результат |
|---|---|
| V2 `scripts/t baseline --compare` | exit 0, missing 0 по всем наборам |
| V4 workflow-policy + WorkflowPolicyTest | exit 0 (33 tests) |
| `scripts/t sqlite -p repo` | 75 passed |
| V1 `all --full` | **прервано**: mysql, ~33 партиции (core целиком, drive/media частично); sqlite/pgsql не доходили |
| V5 pint/phpstan, V6 vue/hub | **не запускались** |

## Замеры (не «до/после»: «до» c64bba25 не снимался, машина шумная)
| Партиция | sqlite | pgsql | mysql |
|---|---|---|---|
| core DatabaseMigrations+TestCase, parallel (11 файлов) | — | 32 с | 702 с |
| core OpenApiTestCase, parallel (6 файлов) | 6 с | 23 с | 411 с |
mysql: datadir на ext4 (/tmp), профиль AppArmor запрещает tmpfs; DDL в состоянии "waiting for handler commit".
pgsql: сервер в /dev/shm. Вывод: локально — sqlite + pgsql, mysql только в финале/CI.

## Падения (mysql, core; не сверены с base c64bba25 — статус «не доказано как регрессия P1»)
- EventRecorderTest: порядок ключей JSON (mysql сортирует ключи JSON-колонки).
- EventTypeTest + ещё 8 тестов в RefreshDatabase+TestCase; P83DocumentsMetricsTest (SQL-ошибка); ProblemMappingTest ×4.
## Падение (pgsql)
- PruneChangesCommandTest «starts a new epoch on reset and refuses an unknown drive»: ResetChangesCommand:32 передаёт
  нечисловой id в bigint-запрос (`v_drives.id = missing`) — SQLSTATE 22P02. Продуктовый код, не харнесс; на base не проверялось.

## Наблюдения о раннере
- «No tests found» в serial-партициях BigintStrategy/OpenApi — разрешено инвентарём (`empty_passes`), раннер печатает
  такой Pest-результат как failed, но вердикт шага — empty (allowed). Дефекта раннера нет.

## Что осталось (перенос на закрытие плана)
V1 полная матрица на трёх движках; замеры до/после на тихой машине; сверка перечисленных падений с base; V5/V6.

## Итог пункта (решение владельца: фазу закрыть без длинных прогонов)
Дополнительно выполнено без длинных прогонов:
- V5: pint и phpstan — root (после правки стиля scripts/*.php и исключения scripts/fixtures-strip из pint.json), media, drive, documents, filament: зелёные. azgard: phpstan не стартует — внешний репозиторий azguard изменился (`legacy/0.3` исчез, ключ `scaffold` отвергается его AzGuardConfig); вне этого репозитория.
- V6: `packages/vue` build/typecheck/api:check и `hocuspocus-server npm run check` — без ошибок.
- Быстрая линия sqlite по всем пакетам — 275 с (загруженная машина); падения, найденные ею, исправлены в P1.12.

**Перенесено на закрытие плана (обязательно, одним прогоном на тихой машине):** `scripts/t all --full --engines=sqlite,pgsql,mysql` (V1),
замер «до» на c64bba25 и «после», сверка с baseline. Политика — tests/README.md, Run policy.
