# 14 — Сценарии проверки

Сценарии — спецификация поведения для тестов, **не** выполненные проверки. V01–V16 взяты у Codex
([04-opus-handoff.md](../04-opus-handoff.md)) и уточнены под принятые решения; V17–V37 — новые.
«PG» — только PostgreSQL (стенд P0.2); «любая» — SQLite достаточно.

## Принятые от Codex (с уточнениями)

| ID | Сценарий | Ожидание после решений | БД | Пункт |
|---|---|---|---|---|
| V01 | Два drive с ULID/UUID7, одинаковые байты, `dedup.scope = drive` | Разные `scope_key`, разные blobs и объекты; purge в одном drive не трогает другой; GC/reconcile распознают оба ключа | любая | P1.5 |
| V02 | Две транзакции на разных соединениях, свободно 10 МБ, два разных файла по 8 МБ | Ровно одна успешна; вторая — `quota_exceeded`; `used_bytes` = сумма голов; blob проигравшей — сирота, удаляемый reconcile | PG | P1.6 |
| V03 | Две новые ревизии одного файла параллельно | Номера 2 и 3 (без дублей и дыр); голова — последняя закоммиченная; квота учла дельты от реальных предыдущих голов | PG | P1.6 |
| V04 | Reader вызывает мутацию через HTTP и через `Vaulter::drive()->actingAs($reader)`; узел из другого drive через handle | Одинаковый `permission_denied`; чужой узел — `node_not_found` | любая | P3.3 |
| V05 | Одни и те же запрещённые байты (exe под именем .pdf) через HTTP single, PHP `UploadSource`, tus, S3 complete | Везде `upload_rejected` (`mime_mismatch`); права проверены на complete | любая | P3.7 |
| V06 | `A→B→A→B` rename; `trash→restore→trash`; повтор доставки webhook | 3 события rename, 3 lifecycle-события; повторная доставка — тот же `event_id` | любая | P1.4, P5.1 |
| V07 | Исключение при записи outbox; «краш» после commit (kill процесса в тесте после `commit`) | Ошибка outbox откатывает мутацию; после commit событие уже в outbox (запись в той же транзакции) | PG | P5.1 |
| V08 | Worker: job тенанта A, затем B; исключение в A; тенант удалён до исполнения | Нет утечки контекста; job без тенанта падает без ретрая; нет all-tenant режима | любая | P3.8 |
| V09 | Allow на предке + deny на ребёнке; первые 300 кандидатов запрещены, 301-й разрешён | Запрещённые не видны в листинге/поиске/abilities; курсор доходит до 301-го; нет `total` | любая | P1.3 |
| V10 | Сторонний тип документа установлен → документ создан → расширение удалено → снова установлено | Узел и контент сохранены; без расширения — `type_available=false`, чтение JSON, edit/export → `document_type_unavailable`; после установки — всё работает | любая | P4.3 |
| V11 | Новый узел схемы + старый клиент коллаборации; save/reconnect | Старый клиент в read-only (schema_version); новый узел не теряется; бинарный state не дублируется | интеграционный (хаб) | P4.5 |
| V12 | Restore ревизии при активном клиенте; затем store со старым epoch; отзыв Edit | Store → 409 `collaboration_epoch_mismatch`, хаб выгружает документ; после отзыва новое подключение read-only | интеграционный | P4.5 |
| V13 | Сбой между promote объекта и SQL commit; GC параллельно writer'у | Сирота удаляется reconcile только после grace и перепроверки; достижимые байты не удалены | PG | P1.5 |
| V14 | `repair-scopes` на blob `legacy:snull` с рёбрами из двух drives; сбой посреди и повтор | Хэш проверен; для каждого drive свой blob; ссылки переключены идемпотентно; старый объект удерживается до GC; неизвестный тенант — в отчёте, не угадан | PG | P1.5 |
| V15 | Чистый хост ставит артефакты 0.3; upgrade БД/конфига 0.2 (фикстура P0.3); старый Vue SDK | Миграции проходят; doctor зелёный; устаревшие ключи работают с предупреждением; старый SDK получает понятную ошибку версии API | PG + MySQL | P2.x, P4.1, P8.2 |
| V16 | `scan.required`, сканер таймаутит; заполненный staging | Голова `failed`/`pending` → download/preview/share → 423; staging-ошибка — `upload_rejected` без строк в БД | любая | P3.7 |

## Новые

| ID | Сценарий | Ожидание | БД | Пункт |
|---|---|---|---|---|
| V17 | `vaulter.database.connection = 'vaulter'` ≠ default; Action бросает исключение после вставки узла | Вставка откатилась на соединении `vaulter`; advisory lock CAS взят на нём же (проверка через `pg_locks`) | PG | P1.1 |
| V18 | Создать папку/загрузить/переместить/скопировать/восстановить с назначением: файл, документ, папка в корзине, корень другого drive | Везде `invalid_destination` или `cross_drive_operation`; корень нельзя переименовать/переместить/удалить | любая | P1.2 |
| V19 | Параллельно `move(A→B)` и `move(B→A)` | Одна успешна, вторая — `node_move_cycle`; цикла в БД нет | PG | P1.2 |
| V20 | Параллельные upload в одну папку с одинаковым именем, `OnNameConflict::Rename` | Оба успешны: `x.pdf`, `x (2).pdf` | PG | P1.6/P3.3 |
| V21 | Move и upload под пересекающимися блокировками (drive → node → aggregate → CAS) | Без deadlock или ≤ 1 ретрай; инварианты сохранены | PG | P1.6 |
| V22 | Драйвер `gate`: Gate разрешает папку, на ребёнке явный deny-grant | Ребёнок не виден в листинге (раньше был виден) | любая | P1.3 |
| V23 | `ids.strategy = ulid`, пользователи хоста UUID, проекты bigint, роли Spatie bigint | Provisioning, grants на пользователя и роль, activity, actor-колонки пишутся и читаются | PG + MySQL | P1.7 |
| V24 | Каждое доменное исключение через HTTP | Статус из таблицы 10 §6, ни одного 500; `code` присутствует | любая | P1.8, P6.2 |
| V25 | Хост задаёт `middleware.authenticated = ['api','auth:sanctum']` | Защищённые маршруты требуют токен; `shares/{token}` и `collaboration/hub/*` работают без него | любая | P1.9 |
| V26 | У хоста маршрут `GET /projects/{node}` с собственной моделью | Резолвится моделью хоста, не `Vaulter\Models\Drive\Node` | любая | P1.9 |
| V27 | Filament: пользователь с Move на узле без Create на целевой папке | Действие недоступно/отклонено; вызов идёт через handle | любая | P1.10, P6.6 |
| V28 | Опубликованный конфиг 0.2 (с `middleware`, `panels`, `share_token_generator`, `enabled_document_types`) | Работает через нормализатор с deprecation; одновременно заданные старый и новый ключи с разными значениями → `InvalidConfigurationException` | любая | P2.5 |
| V29 | Grant Reader пытается комментировать; Commenter — комментирует; Commenter пытается редактировать | Reader → `permission_denied`; Commenter → успех; редактирование → `permission_denied` | любая | P3.5 |
| V30 | `UploadSource::fromStream` с non-seekable потоком 200 МБ при `memory_limit=64M`; PSR-поток; ресурс хоста после вызова | Загрузка успешна без превышения памяти; ресурс хоста остаётся открытым; `fromPath` закрывает свой | любая | P3.7 |
| V31 | Tenancy strict: drive тенанта A запрошен в контексте B через фасад, HTTP и job | Везде `node_not_found`; boot без resolver при `enabled` → исключение | любая | P3.8 |
| V32 | `ids.strategy = bigint`: документ с ревизией id 7 и файл с ревизией id 7 | Скачивание файла и чтение документа возвращают свои ревизии; у узла нет общего указателя | любая | P4.1 |
| V33 | Копия документа с 3 ревизиями и копия папки с документом внутри | Копия имеет контент головы (1 ревизия), свой `v_documents`; purge копии удаляет её ревизии, оригинал цел | любая | P4.2 |
| V34 | Загрузка `.docx`, `.md`, `.xlsx` файлов | Presentation: preview/download, `editable=false`, `collaborative=false` | любая | P4.3 |
| V35 | `drives.delete_with_owner = true`, владелец удалён | drive → `deleting` → purge → строки удалены; GC освобождает неиспользуемые blobs; activity `drive.deleted` | любая | P4.6 |
| V36 | Два клиента переименовывают узел с одинаковым `If-Match` | Первый — 200 и новый ETag; второй — 412 `node_sequence_mismatch` | любая | P4.7 |
| V37 | Vue: узел с `viewer.key = 'acme/unknown'` и документ с `type_available=false` | Показан `VaulterUnsupportedNode` с кнопкой скачивания по abilities | vitest | P6.4 |

## Покрытие матрицы (не полный декартов продукт)

| Измерение | Где обязательно |
|---|---|
| `ids.strategy` × `ids.host_keys` | V01, V14, V23, V32 и provisioning/access suites |
| `dedup.scope` | V01, V13, V14 |
| PostgreSQL | все «PG» сценарии + feature suites |
| MySQL 8 | V15, V23, миграции, инварианты I1–I3 |
| Laravel 12/13 | все suites (кроме filament — только 13) |
| `config:cache` / `route:cache` | V25, V28, регистрация реестров |
| Драйверы прав database/gate/spatie/azgard | контрактный набор драйверов + V09, V22, V29 |
