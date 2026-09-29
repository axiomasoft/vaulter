# Передача Opus 5.5

Цель следующего прохода: независимо оспорить/дополнить выводы, выбрать контракты и только затем построить план. База исследования уже лежит в этой папке; повторный общий web search не требуется для перечисленных решений. Если обнаружится принципиально новая тема, отметить пробел, а не считать любой поисковый ответ доказательством.

## Решения, которые нельзя заменить списком рефакторингов

| Вопрос | Предпочтение Codex | Реальная альтернатива / условие выбора |
|---|---|---|
| Гарантия public PHP API | Общая авторизованная application surface для HTTP/PHP/jobs | Доверенный low-level API допустим, если отдельно назван и его граница явно документирована |
| Tenancy | Явный контекст public operation; строгий отказ при недостающем tenant в выбранном режиме | Сохранить host-owned tenancy с более сильными integration checks; учитывать ADR и maintenance |
| CAS identity | Lossless строковый scope; согласованные lookup/locks/GC | Disk-agnostic либо disk-specific размещение выбрать отдельно, с upgrade/repair данными |
| Quota | Сохранить head-byte semantics, сделать enforcement атомарным | Retained/physical quotas вводить как отдельное продуктовое решение |
| События | Occurrence ID + отдельно command key; durable outbox для надёжных интеграций | Best-effort уведомления с явной гарантией и recovery, если это сознательный продуктовый контракт |
| Document extension | Тип/схема отделены от MIME и редактора; использовать текущий DocumentContent | Минимальное усиление существующего handler registry, если оно докажет lifecycle нового типа |
| ORM и naming | Laravel-native PHP API, точечные VO/DTO, короткий glossary | Полный reference-only API оправдать конкретным потребителем, а не эстетикой |
| Package boundaries | Сохранить набор пакетов, закрепить ownership и dependency tests | Выделение пакета — при независимой установке/релизе или тяжёлой optional dependency |
| HTTP compatibility | OpenAPI + consumer scenarios + выбранное окно поддержки | URL version и header version оба допустимы; выбрать один и описать upgrade |
| ACL listing | Metadata видны только при точном effective view | Если есть отдельное browse/discovery право, определить его явно, а не случайным SQL superset |

## Проверочные сценарии для будущего плана

Это заготовки поведения, **не новые тесты и не результаты выполненных проверок**. Набор разбивается по пакетам после выбора контрактов.

| ID | Сценарий | Что должно быть наблюдаемо | Основание |
|---|---|---|---|
| V01 | Два storage с ULID/UUID7, одинаковые bytes, storage dedup | Разные scope/key/blob; удаление одной группы не затрагивает другую; GC распознаёт оба ключа | E1, S09–S12 |
| V02 | Две DB connections: свободно 10, два разных файла по 8 | Только допустимый объём committed; предметный отказ второго; нет потерянных refs | E2, S09 |
| V03 | Две новые версии одного файла, разные размеры | Уникальные versions, согласованный head и delta; documented retry/conflict | E2/E10 |
| V04 | Reader вызывает mutation через HTTP и public PHP; target из другого storage | Одинаковый отказ; actorId не превращает Reader в Writer | E3, S04 |
| V05 | HTTP/PHP/tus/S3 completion с теми же запрещёнными bytes | Единая policy; нельзя обойти запрет выбором транспорта; права проверены на finalize | E4, S02/S06–S08 |
| V06 | Три rename `A→B→A→B`, затем повтор доставки | Три occurrence IDs; retry сохраняет ID; consumer effect ровно один на occurrence | E8, S13–S15 |
| V07 | Crash после business commit; либо ошибка записи outbox | При durable policy mutation и outbox фиксируются вместе; при best-effort ограничение явно отражено и восстанавливаемо | E7, S13 |
| V08 | Queue/worker последовательно A, B; исключение в A; tenant удалён до job | Нет утечки предыдущего context; нет неявного all-tenant режима | E9, S04–S05 |
| V09 | Ancestor allow + child deny; первые N кандидатов denied | Нет metadata/search/count утечки; continuation достигает последующих allowed | E10, S30 |
| V10 | Сторонний document type установлен → создан → extension удалена | Сохранены bytes/identity; безопасный unknown-type fallback; обратная установка читает документ | E5, S01/S03/S18 |
| V11 | Новый schema node + старый collaborative client; save/reconnect | Новый node не теряется, binary state не дублируется | S16–S18 |
| V12 | Restore при активном/offline клиенте; затем старые updates; revoke write | Выбранная restore/revoke семантика соблюдена всеми серверами и клиентами | S17–S18 |
| V13 | Сбой между object promote и SQL commit; GC конкурирует с writer | Orphan безопасно обнаруживается; достижимые bytes не удаляются | E1, S10–S12 |
| V14 | Repair старого `snull` blob с несколькими edges и повтор после сбоя | Hash проверен, refs переключены идемпотентно; старые bytes удержаны; неизвестный tenant не придуман | E1, раздел 3 позиции |
| V15 | Чистый host устанавливает архив; upgrade старой БД/config; старый Vue SDK | Работают declared dependencies/provider/migrations; aliases предсказуемы; schema/API совместимы | S21/S23–S27 |
| V16 | Restore DB+objects+Yjs, scan timeout, заполненный staging, работа с деревом без мыши | Целостность проверяема; выбранная policy не выпускает непроверенные bytes; отказ понятен и UI доступен | S07/S17/S22/S28–S29 |

Не выполнять полный декартов продукт всех backend/ID/версий в каждом тесте. ID×scope обязательно для CAS; PostgreSQL concurrency — для locking; supported framework matrix — для consumer contracts. Реальные внешние сервисы нужны там, где их поведение существенно и не покрывается fake. Перед DB-тестами следовать проектному preflight и изоляции.

## Как оформить независимый результат

Предлагаемый файл — `05-opus-review.md`. Достаточно четырёх частей: согласен/оспариваю с основанием; выбранные решения и отвергнутые альтернативы; оставшиеся вопросы владельцу; границы будущего плана и проверки. Мой текст не нужно переписывать под согласованную версию: полезно сохранить разницу суждений.

Перед планом отдельно установить, существуют ли уже реальные consumer installations и данные со старыми scopes. От этого зависит необходимость compatibility wrappers и repair; отсутствие стабильного 1.0 ещё не доказывает отсутствие пользователей. Также нужна выбранная гарантия webhook delivery и видимости metadata. Эти продуктовые факты не находятся в web search.

Не превращать автоматически все предложения исходного аудита в release blockers. CAS scope loss и event deduplication имеют конкретные failure paths; переименование папок, отказ от Eloquent и число конфигов — варианты дизайна. Не предлагать добавлять уже имеющиеся after-commit markers, cursor pagination, GC и unique indexes с нуля.

## Что осталось непроверенным

В этом проходе нет dynamic exploit/reproduction, свежего CI, PostgreSQL concurrency runs, сборки release artifacts, S3/Hocuspocus интеграционного прогона, замеров нагрузки и полного security review. Материалы позволяют выбирать решения и спроектировать проверки; статус release readiness они не устанавливают.
