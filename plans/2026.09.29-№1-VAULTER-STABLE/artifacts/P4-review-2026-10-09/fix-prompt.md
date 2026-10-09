# Промпт: закрыть все находки двойного ревью P4 (R1–R13)

Ты — исполнитель в репозитории `vaulter` (корень — текущая директория `/home/vostrikov/projects/packages/vaulter`).
План: `plans/2026.09.29-№1-VAULTER-STABLE` (JSON — источник истины; `views/` генерируется и не правится).
Задача: **закрыть все находки** ревью 2026-10-09 так, чтобы хвостов не осталось. Ревью завершено, вердикт RED;
владелец решил исправлять всё сейчас, без переноса.

## Вход

- Отчёт: `plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P4-review-2026-10-09/report.md` (таблица R1–R13 с `file:line`,
  сценарием отказа и основанием). Документ плана: `knowledge/review-p4-post-midaudit-2026-10-09.json`.
- Результат Grok: `…/grok-result.txt` (G1–G4 = R2, R5, R6, R4).
- Нормы: `CLAUDE.md`, `.claude/rules/**`, решения `decisions/D14, D15, D17, D23, D24, D28, D34–D37, D44, D51, D54, D56, D57`,
  контракт `knowledge/P4-design.json`, инварианты `knowledge/P4-invariants.json`.
- Работай только в затронутых `packages/<name>/`, `hocuspocus-server/`, `docs/`, `UPGRADE.md`, `CHANGELOG.md`; чужие правки и
  `plans/**/views/**` не трогай. Находки, которые отчёт отнёс к MOVE-зоне следующего item, не открывай.

## Что исправить (по приоритету)

| ID | Sev | Что сделать |
|---|---|---|
| R1 | Blocker | Хаб проецирует link-марку с `title: null`, PHP-аллоу-лист (`TipTapSchema::ATTRIBUTES['link']`) его отвергает → 422 на каждом store/seed документа со ссылкой. Привести две стороны к одному набору: разрешить `title` для `link` (атрибут TipTap Link) и/или не отдавать null-атрибуты из проекции хаба — выбери вариант, согласованный с D-решениями, и зафиксируй. Расширить `schema-parity` (хаб ↔ `tests/Fixtures/tiptap-schema.json`) до **атрибутов**, добавить тест «проекция хаба для документа со ссылкой/картинкой/таблицей проходит `TipTapContent::fromArray`» и тест хаба с link-маркой. |
| R2 | Blocker | `hocuspocus-server/src/index.js` `onLoadDocument`: при `shared=true` read-only коннект к документу без `yjs_state` не должен оставлять в памяти пустой Y.Doc (писатель потом затирает JSON). Рекомендация: отказать такому коннекту (ошибка, документ не кэшируется) до seed писателем; клиент повторяет. Тест в `test/load-seed.test.js`: `shared=true`, ro-load → rw-store не уходит с пустой проекцией. |
| R3 | Major | `packages/azgard`: `AzgardGuard` в контейнере строится по `Auth::guard()->user()`, а не по `$actor`. `AzgardPermissionDriver::guardFor()` должен строить адаптер по самому `$actor` (если он `AzgardBackedUser`), с `panel_id`; контейнерный guard — только запасной путь, и не для другого пользователя. Тесты: actor ≠ пользователь запроса (нет эскалации), нет пользователя запроса (вызов хаба — решение по `$actor`). Обновить README/доки моста и CHANGELOG/UPGRADE. |
| R4 | Major | `hocuspocus-server/src/index.js` `onStoreDocument`: ответы 401/403/413/422 не должны считаться успешным сохранением. Определить поведение: 403/401 — закрыть комнату/соединение явным кодом и сообщить клиенту; 413/422 — не терять молча (лог + закрытие или повтор по политике), не позволять Hocuspocus выгрузить несохранённое. Описать коды в `docs/guide/collaboration.md` (en+ru) и в клиенте `sessionEnd`, если добавляется код. Тесты хаба на каждый статус. |
| R5 | Major | Seed: `PersistCollaborationStateAction` сравнивает только `epoch` и `yjs_state IS NULL`. Добавить предусловие по `state_version` (хаб отправляет `state_version`, полученный в `collab/load`, backend сверяет под D-lock; несовпадение → 409 с кодом, хаб загружает заново). Обновить `docs/reference/concurrency.md` (en+ru), UPGRADE, тест `HubProtocolTest`: REST-update между load и seed → 409, текст не потерян. |
| R6 | Major | Seed хаба молча вырезает атрибуты из `tiptap.extra_attributes`. Fail-closed: если проекция seed теряет что-либо из входного JSON (сравнение до/после), seed отклонять с ясной ошибкой, либо сделать схему хаба конфигурируемой из тех же `extra_*`. Документировать в `hocuspocus-server/README.md` и `docs/guide/hocuspocus-server.md`; тест с `textAlign`. |
| R7 | Major | Хаб при переподключении с просроченным токеном должен отдавать причину `Collaboration token expired` (проверять `exp` до вызова backend в `onAuthenticate`), чтобы `sessionEnd` в `packages/vue/src/nodeCollaboration.ts` обновил сессию. Тесты хаба и vue unit. |
| R8 | Major | `packages/vue/src/components/VaulterDocumentNode.vue`: падение на REST-автосохранение допустимо только когда коллаборация недоступна по контракту (`409 collaboration_unavailable`/выключена); на transient-ошибки (сеть, 429, 5xx) — повтор/read-only, **не** автосохранение в документ с Yjs-state. Тесты компонента. |
| R9 | Minor | Проверка в `DocumentsConfig`/boot: `jwt_secret` ≠ `webhook_secret` (`InvalidConfigurationException`), тест; убрать `VAULTER_COLLAB_SECRET` из `hocuspocus-server/.env.example` (если правка файла заблокирована правилами доступа — зафиксируй и вынеси владельцу одним пунктом). |
| R10 | Minor | Добавить `Vaulter\Documents\Export\DocumentExporterRegistry` (и `Data\Export\ExportDocumentInput`, если публичный) в `PublicApiSnapshotTest` и обновить `tests/Arch/__snapshots__/public-api.txt` штатной процедурой AMEND. |
| R11 | Minor | `ExportDocumentAction`: метка метрики `format` — только значение, известное реестру для типа, иначе фиксированный `unknown`; согласовать с `load/app/Observability/*` (допустимые значения). Тест. |
| R12 | Minor | `DocumentExport`: `sourceEpoch` для экспорта ревизии — определить и документировать смысл (поколение документа на момент экспорта) и пометить экспорт lossy-чтения (поле/заголовок или документированное ограничение). Обновить snapshot и доки. |
| R13 | Nit | ru-зеркало `docs/ru/guide/collaboration.md` довести до структуры en (включая «Extending», подраздел `VaulterDocumentNode`, блоки кода); удалить неиспользуемый `CollaborationConfigException::noVerifier()`; в UPGRADE/доках экспортёров уточнить, что arch-правило касается только встроенных экспортёров (для хост-классов — рекомендация). |

## Правила исполнения

1. Для каждой находки: сначала тест, который краснеет на текущем коде (негативный контроль D51: воспроизведи → красный →
   исправление → зелёный), потом исправление. Тест не должен проходить на неправильной реализации.
2. Документация и `CHANGELOG.md`/`UPGRADE.md` — в тех же изменениях; en и ru зеркала согласованы с кодом.
3. Публичный API/BC — по `.claude/rules/**`; breaking-изменения фиксируй в UPGRADE.
4. Проверки (разрешены): Pest пакета на SQLite
   `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci …` из cwd пакета
   (documents, azgard, при затрагивании — core), `phpstan analyse --memory-limit=512M --no-progress`, Pint на изменённых PHP,
   `cd hocuspocus-server && node --test`, для `packages/vue` — unit/typecheck скрипты. Запрещено: PostgreSQL/MySQL, e2e
   процессов хаба, мутационное тестирование, CI dispatch, composer. Что нельзя проверить без запрещённого — перечисли
   точной командой в итоговом отчёте, не выдавай за GREEN. Пропуск или падение ≠ успех.
5. Временные файлы — вне `src/`, удаляй (`python3 -c "import shutil; …"`, `rm` может блокироваться хуком).
6. Коммиты и push — только если владелец явно скажет; иначе оставь изменения в рабочем дереве.

## Закрытие

- Обнови состояние находок через штатный API плана (`plan.py log …`, при необходимости `add-document` с id вида
  `review-p4-post-midaudit-2026-10-09-resolution`); `views/**` не правь, состояние items не меняй без полномочий
  (`reopen`/`grant` — решение владельца).
- Допиши в `artifacts/P4-review-2026-10-09/` файл `resolution.md`: таблица R1–R13 → статус (fixed / fixed+test / отложено
  владельцу с причиной), список изменённых файлов, результаты проверок (команда → exit code), что осталось на тяжёлые
  проверки (точные команды).
- Финальный ответ владельцу — коротко: что закрыто, что не закрыто и почему, результаты проверок, состояние
  рабочего дерева (`git status`), и строка `python3 <plan.py> next 2026.09.29-№1-VAULTER-STABLE`.
