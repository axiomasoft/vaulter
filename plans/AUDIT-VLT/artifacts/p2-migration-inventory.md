# Migration inventory archive

## 1

AUDIT-VLT

## 2

Аудит пакета vaulter: безопасность, встраиваемость, гибкость, качество

## 3

0.1.0

## 4

🟡 In progress

## 5

Executable Master Plan

## 6

opus

## 7

2026-07-10

## 8

/home/vostrikov/projects/packages/vaulter (axioma-studio/vaulter)

## 9

vaulter-core, vaulter-drive, vaulter-media, vaulter-documents, vaulter-filament, vaulter-permissions-azgard, @vaulter/vue, hocuspocus-server

## 10

phase-first

## 11

sonnet (exec) · opus (P0.2 RAG, P5 синтез)

## 12

Dmitry Vostrikov

## 13

repo:plans/AUDIT-VLT

## 14

—

## 15

⬜ Not started

## 16

Собрать фактическую опись поставляемой поверхности vaulter (пакеты, публичные классы, конфиг-ключи, миграции, маршруты, тесты, команды, тулинг) и зафиксировать её как отправную точку аудита в `findings/P0.1-baseline-inventory.md`.

## 17

Все последующие фазы измеряют код против норм; без точной описи «сколько чего есть» аудит не сможет доказать полноту покрытия, а закрытие плана будет ложным чувством покрытия (pre-mortem R5.3, `findings/P0-premortem.md`). [UNVERIFIED — внутренний факт репозитория, RAG не требуется]

## 18

`packages/{core,drive,media,documents,filament,permissions-azgard}/src`, `packages/vue/src`, `packages/core/config/vaulter.php`, `packages/core/database/migrations`, `packages/*/routes`, `packages/*/tests`, корневые `composer.json`, `Makefile`, `phpstan.neon`, `pint.json`, `rector.php`, `infection.json5`, `phpunit.xml`, `testbench.yaml`, `e2e/`, `hocuspocus-server/`.

## 19

`vendor/`, `node_modules/`, `packages/*/vendor`, `packages/vue/dist`, `art/`, любые вердикты о качестве/дефектах, любые изменения файлов.

## 20

`plans/AUDIT-VLT/plan.md` (§2 Execution Rules, D1–D6) · `plans/AUDIT-VLT/artifacts/finding-template.md` (схема, пост-условия).

## 21

создаёт `plans/AUDIT-VLT/findings/P0.1-baseline-inventory.md`. Продакшн-файлы — только чтение.

## 22

1) `plans/AUDIT-VLT/plan.md` 2) `plans/AUDIT-VLT/artifacts/finding-template.md` 3) `composer.json` (корень) 4) `Makefile` 5) `packages/core/config/vaulter.php` 6) `packages/core/src/VaulterServiceProvider.php` 7) `packages/core/src/Facades/Vaulter.php` 8) `packages/core/src/VaulterManager.php` 9) `docs/adr/index.md` 10) `docs/plan/00-master-plan.md`

## 23

Числа берутся из команд, а не «на глаз»; каждая цифра в отчёте сопровождается породившей её командой. Разрешённые команды (read-only): `find`, `grep`, `wc`, `git status`, `git log --oneline -5`, `/usr/bin/php8.4 vendor/bin/pest --list-tests` (перечисление без прогона), `composer show --direct` (без записи в lock). Прогон полного тестового набора здесь НЕ требуется. Установка зависимостей, `composer update/install`, `npm install` — ЗАПРЕЩЕНЫ.

## 24

Это опись, а не ревью — ЗАПРЕЩЕНО заводить находки, писать оценки («слишком много», «плохо структурировано») и предлагать рефакторинг; для P0.1 секция «Находки» файла-шаблона остаётся пустой со строкой «— (инвентаризация, находок по определению нет)». ЗАПРЕЩЕНО помечать item 🟢 при неполной описи: каждый из 7 пакетов обязан иметь строку в таблице «пакет | composer name | версия require php/laravel | кол-во php-файлов | кол-во тестов | наличие phpstan.neon». Обходить гейт заглушкой («данные уточню позже») запрещено — при недоступности данных статус остаётся 🟡 + Pending Work с точной причиной. Если файла из Required Reads не существует — НЕ придумывать содержимое: эскалация по `plan-protocol` §10, trigger `план разошёлся с кодом`.

## 25

1) файл `plans/AUDIT-VLT/findings/P0.1-baseline-inventory.md` существует и содержит секции 1–4 шаблона; 2) `git status --porcelain -- packages docs e2e hocuspocus-server` даёт пустой вывод (D2); 3) `python3 "/home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-lint.py" plans/AUDIT-VLT` — без ошибок; 4) в отчёте перечислены ВСЕ ключи первого уровня `config/vaulter.php` (сверить `grep -cE "^    '[a-z_]+' =>" packages/core/config/vaulter.php` с числом строк таблицы конфига).

## 26

`plans/AUDIT-VLT/findings/P0.1-baseline-inventory.md` с таблицами: (а) пакеты, (б) конфиг-ключи `vaulter.php` первого уровня + что каждый переключает, (в) миграции `packages/core/database/migrations` (файл → таблица), (г) HTTP-маршруты по файлам `routes/*.php` (только счётчик и файл; детализация — P0.3), (д) artisan-команды, (е) тулинг качества (pint/phpstan level/rector/infection/pest + фактические команды из Makefile), (ж) фронтенд и Docker-поверхность (`packages/vue/src`, `e2e/docker`, `hocuspocus-server/`). Плюс строка «Не проверено / ограничения».

## 27

—

## 28

—

## 29

—

## 30

no

## 31

⬜ Not started

## 32

Верифицировать через context7/perplexity те внешние нормы, которые зависят от версий и потому не могли быть зафиксированы на этапе проектирования, и записать их в `findings/P0.2-rag-supplement.md` как дополнение к нормам P0-выжимок.

## 33

Design-time RAG (2026-07-10) закрыл нормы протоколов и практик (RAG:✅ 2026-07-10 — `findings/P0-{dms-security,upload-hardening,collab-seam,embeddable-laravel}.md`), но версии библиотек, статусы CVE и API-контракты SDK меняются между проектированием и исполнением; сверка кода с устаревшей нормой порождает ложные находки (pre-mortem R3.1, D9).

## 34

верификация по темам T1–T6 (ниже) с фиксацией URL + даты + вердикта.

## 35

чтение кода vaulter (кроме `composer.json` для установленных версий); вердикты о дефектах; переписывание существующих `findings/P0-*.md` (они датированы и неизменны — новые факты пишутся в P0.2-файл со ссылкой «уточняет U-х.х»).

## 36

`findings/P0-upload-hardening.md` (U1–U7) · `findings/P0-collab-seam.md` (C1–C5) · `findings/P0-embeddable-laravel.md` (E1–E7) · `packages/*/composer.json` (фактические констрейнты).

## 37

создаёт `plans/AUDIT-VLT/findings/P0.2-rag-supplement.md`.

## 38

1) `plans/AUDIT-VLT/findings/P0-upload-hardening.md` 2) `plans/AUDIT-VLT/findings/P0-collab-seam.md` 3) `plans/AUDIT-VLT/findings/P0-embeddable-laravel.md` 4) `packages/core/composer.json` 5) `packages/documents/composer.json` 6) `packages/drive/composer.json` 7) `packages/media/composer.json` 8) `plans/AUDIT-VLT/artifacts/finding-template.md`

## 39

RAG-лестница строго: `context7` → `perplexity-web` → `WebSearch`. Каждый факт получает `RAG:✅ <дата> <URL>` либо остаётся `[UNVERIFIED]` — третьего не дано. Темы, обязательные к закрытию: **T1** `firebase/php-jwt` — текущая мажорная версия, обязателен ли явный allow-list алгоритмов в `JWT::decode`, поведение при `alg: none` (уточняет C2.1–C2.2). **T2** `league/flysystem` + `league/flysystem-aws-s3-v3` — актуальный API `writeStream`/`temporaryUrl`, гарантии атомарности `move`. **T3** `@hocuspocus/server` — текущая мажорная версия, контракт хуков `onAuthenticate`/`onStoreDocument`, встроенная поддержка `readOnly` (уточняет C5.3). **T4** `staudenmeir/laravel-adjacency-list` — актуальная версия и известные ограничения `ancestors`/`descendants` на PostgreSQL/SQLite. **T5** OWASP ASVS (актуальная мажорная версия) — глава про File Upload: перечислить требования уровня L2 (дополняет U1–U5). **T6** Laravel — актуальная поддерживаемая мажорная линия и матрица `orchestra/testbench` ↔ Laravel (нужно для P4.4). Если context7 не знает библиотеку — фиксировать это явно и переходить к perplexity, не выдумывать.

## 40

ЗАПРЕЩЕНО писать версию/норму «по памяти» — только с URL и датой; факт без URL пишется как `[UNVERIFIED]` и это легальный исход, а тихая подстановка правдоподобной версии — дефект исполнения. ЗАПРЕЩЕНО править датированные выжимки `findings/P0-*.md` (они — снимок); противоречие оформляется строкой «T-х уточняет/опровергает U-х.х: <в чём>». ЗАПРЕЩЕНО помечать item 🟢, если хотя бы одна из тем T1–T6 не имеет ни `RAG:✅`, ни явного `[UNVERIFIED]` с причиной. Если найденная норма прямо противоречит уже написанному в P0-выжимке — НЕ продолжать самовольно: это меняет основание D3/D9, эскалация по `plan-protocol` §10 (trigger: `rule conflict`).

## 41

1) `findings/P0.2-rag-supplement.md` содержит ровно 6 подсекций T1–T6, каждая — с вердиктом и URL/`[UNVERIFIED]`; 2) `grep -c "RAG:✅\|\[UNVERIFIED\]" plans/AUDIT-VLT/findings/P0.2-rag-supplement.md` ≥ 6; 3) `git status --porcelain -- packages docs e2e hocuspocus-server` пуст; 4) `python3 "/home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-lint.py" plans/AUDIT-VLT` — без ошибок.

## 42

`plans/AUDIT-VLT/findings/P0.2-rag-supplement.md` (темы T1–T6, каждая: запрос → источник+дата → вердикт → как уточняет нормы U/C/E) + секция «Не проверено / ограничения».

## 43

—

## 44

—

## 45

—

## 46

no

## 47

⬜ Not started

## 48

Построить исчерпывающую таблицу «HTTP-маршрут → контроллер@метод → middleware-стек → способ авторизации (Policy / AccessResolver / Ability / отсутствует)» по всем `routes/*.php` пакета и записать её в `findings/P0.3-attack-surface-map.md`.

## 49

OWASP A01:2025 (Broken Access Control) требует объектной авторизации на КАЖДЫЙ запрос (RAG:✅ 2026-07-10 — `findings/P0-dms-security.md` §S3.1); проверить это без полной карты входных точек нельзя, а items P1.3/P1.4/P1.6/P1.8 стоят на ней как на входе.

## 50

`packages/core/routes/api.php`, `packages/drive/routes/drive.php`, `packages/documents/routes/documents.php`; регистрация маршрутов в `VaulterServiceProvider`/`VaulterDriveServiceProvider`/`VaulterDocumentsServiceProvider`; middleware в `packages/core/src/Http/Middleware/*`, `packages/documents/src/Http/Middleware/*`; политики `packages/core/src/Policies/*`.

## 51

Filament-ресурсы (`packages/filament`) — их поверхность разбирается в P2.5/P4.5; вердикты о дефектах (только фиксация факта «авторизация отсутствует» в колонке, без severity — заводить находки будет P1.8); фронтенд-роутинг.

## 52

`findings/P0.1-baseline-inventory.md` (список файлов маршрутов) · `findings/P0-dms-security.md` (S3.1–S3.3).

## 53

создаёт `plans/AUDIT-VLT/findings/P0.3-attack-surface-map.md`.

## 54

1) `plans/AUDIT-VLT/findings/P0.1-baseline-inventory.md` 2) `packages/core/routes/api.php` 3) `packages/drive/routes/drive.php` 4) `packages/documents/routes/documents.php` 5) `packages/core/src/VaulterServiceProvider.php` 6) `packages/drive/src/VaulterDriveServiceProvider.php` 7) `packages/documents/src/VaulterDocumentsServiceProvider.php` 8) `packages/core/src/Http/Middleware/ResolveShareToken.php` 9) `packages/documents/src/Http/Middleware/ResolveCollaborationCall.php` 10) `packages/core/src/Policies/NodePolicy.php` 11) `packages/core/src/Policies/WebhookEndpointPolicy.php` 12) `packages/core/src/Enums/Access/Ability.php` 13) `packages/core/config/vaulter.php` (ключи `route_prefix`, `middleware`, `features`)

## 55

Одна строка таблицы на маршрут (метод + URI + имя). Колонки: `Метод | URI | Имя | Контроллер@метод | Feature-флаг (из config.features) | Middleware-стек (полный, включая групповой из config.middleware) | Авторизация | Модель-биндинг`. Колонка «Авторизация» заполняется ОДНИМ из значений: `Policy:<класс>@<метод>` · `AccessResolver::<метод>(<Ability>)` · `ShareToken` · `WebhookSecret|JWT` · `отсутствует` — значение подтверждается `file:line` в соседней колонке. Маршруты, зарегистрированные условно (`features()`), помечаются флагом. Использовать статическое чтение файлов; `php artisan route:list` в пакете без хоста недоступен — если удастся получить список через `vendor/bin/testbench route:list`, приложить его вывод в `artifacts/repro/P0.3/route-list.txt` как перекрёстную сверку (расхождение статики и рантайма — само по себе строка в «Не проверено / ограничения»).

## 56

ЗАПРЕЩЕНО выводить авторизацию «по названию контроллера» — только по фактическому коду метода (`authorize()`, `Gate::`, `AccessResolver`, `$this->resolver->can(...)`), со ссылкой `file:line`. Пустая клетка недопустима: если способ не найден — писать `отсутствует` и это будет входом для P1.8, а не «наверное, есть в middleware». ЗАПРЕЩЕНО в этом item'е выставлять severity и заводить находки — это карта, а не вердикт (секция «Находки» = «— (карта, вердикты в P1)»). ЗАПРЕЩЕНО помечать item 🟢, если число строк таблицы меньше числа `Route::`-вызовов в трёх файлах маршрутов (сверить `grep -cE "Route::(get|post|put|patch|delete|any|match)" packages/*/routes/*.php`); при расхождении — 🟡 + Pending Work с точной причиной.

## 57

1) `findings/P0.3-attack-surface-map.md` существует, таблица покрывает все маршруты (сверка счётчиком `grep -cE "Route::(get|post|put|patch|delete|any|match)"` по трём файлам); 2) в колонке «Авторизация» нет пустых значений; 3) `git status --porcelain -- packages docs e2e hocuspocus-server` пуст; 4) `python3 "/home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-lint.py" plans/AUDIT-VLT` — без ошибок.

## 58

`plans/AUDIT-VLT/findings/P0.3-attack-surface-map.md` (полная таблица маршрутов + отдельная подтаблица «маршруты без объектной авторизации» + секция «Не проверено / ограничения»). Опционально `plans/AUDIT-VLT/artifacts/repro/P0.3/route-list.txt`.

## 59

—

## 60

—

## 61

—

## 62

no

## 63

⬜ Not started

## 64

Проверить, что `Vaulter\Access\AccessResolver` реализует объявленный алгоритм H.2 без обходов: host-override → ограниченный обход предков → allow/deny-маски → роль → финальное deny-вето; и что deny нельзя воскресить ролью, ни соседним allow, ни пересечением storage.

## 65

Это единственная точка объективной авторизации пакета — централизованный `can(user, action, node)` (RAG:✅ 2026-07-10 — `findings/P0-dms-security.md` §S1.6). Ошибка свёртки ACL здесь = тихий обход прав на всём дереве; deny-вето и «hard deny»-стена (`inherit_grants=false`) — нормы S1.1/S1.4.

## 66

`AccessResolver`, `GrantSet`, `Decision`, `AclScope`, `Subject`, `NodeAbilities`, `Ability`/`Effect`/`GrantLevel`/`GrantScope`, `GrantReadRepository`, миграция `..._create_v_node_grants_table.php`, существующие тесты доступа.

## 67

реализация конкретных `HostRoleDriver` (P1.2); индексы и производительность обхода (P4.1/P4.2); UI-флаги abilities во фронтенде.

## 68

`findings/P0-dms-security.md` §S1 (S1.1–S1.8) · `findings/P0.3-attack-surface-map.md` · `docs/adr/0002-authorization-delegation.md` · `docs/adr/0007-pluggable-authorization.md`.

## 69

создаёт `plans/AUDIT-VLT/findings/P1.1-access-resolver.md`; опционально `plans/AUDIT-VLT/artifacts/repro/P1.1/`.

## 70

1) `plans/AUDIT-VLT/artifacts/finding-template.md` 2) `plans/AUDIT-VLT/findings/P0-dms-security.md` 3) `docs/adr/0002-authorization-delegation.md` 4) `docs/adr/0007-pluggable-authorization.md` 5) `packages/core/src/Access/AccessResolver.php` 6) `packages/core/src/Access/ValueObjects/GrantSet.php` 7) `packages/core/src/Access/ValueObjects/AclScope.php` 8) `packages/core/src/Access/ValueObjects/Subject.php` 9) `packages/core/src/Access/ValueObjects/Decision.php` 10) `packages/core/src/Access/Abilities/NodeAbilities.php` 11) `packages/core/src/Enums/Access/Ability.php` 12) `packages/core/src/Enums/Access/Effect.php` 13) `packages/core/src/Enums/Access/GrantLevel.php` 14) `packages/core/src/Enums/Access/GrantScope.php` 15) `packages/core/src/Repositories/Access/GrantReadRepository.php` 16) `packages/core/src/Models/Access/NodeGrant.php` 17) `packages/core/src/Models/Drive/Node.php` 18) `packages/core/database/migrations/2026_06_13_000013_create_v_node_grants_table.php`

## 71

Закрытый чеклист (вердикт обязателен по каждой строке):
`C1` deny-вето применяется ПОСЛЕ свёртки роли — роль не может воскресить denied-бит (S1.1);
`C2` deny применяется к составным abilities: deny на под-право снимает его из составного allow, а не отбрасывает весь allow (S1.3);
`C3` свёртка идёт per-subject-set: deny, адресованный другому субъекту, не влияет на актора (S1.2);
`C4` обход предков **не пересекает** `storage_id`: грант из чужого storage не может попасть в свёртку (проверить SQL/relation в `GrantReadRepository` и границу в `AccessResolver`);
`C5` `inherit_grants=false` — стена: гранты выше неё игнорируются, но собственные гранты узла-стены учитываются (S1.4);
`C6` deny на узле-стене не пробивается более близким allow (объявленная семантика докблока — сверить с кодом);
`C7` истёкшие гранты отфильтрованы в запросе (read-time expiry), а не после свёртки;
`C8` `override(...) === Effect::Deny` даёт абсолютный отказ, не проходящий дальше по алгоритму;
`C9` `abilitiesFor()` и `can()` дают согласованный результат для каждой `Ability` (одна свёртка, не две реализации);
`C10` `sharedNodeIds()` + `filterSharedWith()` не выдают узел, на который есть более близкий deny (S1.8 — множественные пути к объекту);
`C11` неявные права (владелец узла, создатель storage) — существуют ли они в обход ACL и видимы ли в модели (S1.5);
`C12` есть ли механизм «explain access» (какой ACE дал вердикт) — S1.7; отсутствие = находка Minor.
Для `C4`, `C5`, `C6`, `C10` — обязателен repro: временный Pest-тест в `artifacts/repro/P1.1/`, запускаемый через `/usr/bin/php8.4 vendor/bin/pest <путь>`; фактический вывод цитируется в находке.

## 72

Докблок `AccessResolver` описывает алгоритм H.2 — он **не является доказательством**: расхождение кода с докблоком = находка (Major), а не повод считать код верным. ЗАПРЕЩЕНО заводить находку про «неоптимальный обход дерева» — это ось P4. ЗАПРЕЩЕНО править `AccessResolver` или его тесты (D2); repro-тесты живут только в `artifacts/repro/P1.1/` (D6). ЗАПРЕЩЕНО помечать item 🟢 при незаполненном чеклисте: каждая строка `C1..C12` обязана нести вердикт с `file:line`; «не смог проверить» → `⚠ partial` + строка в «Не проверено / ограничения», а не молчаливый `pass`. Если repro показывает обход авторизации — НЕ чинить: завести Blocker, добавить строку в REGISTER, продолжить чеклист. Если код противоречит `docs/adr/0002`/`0007` — не продолжать самовольно с трактовкой «ADR прав»: зафиксировать оба варианта и эскалировать по `plan-protocol` §10 (trigger: `rule conflict`), это меняет основание D5.

## 73

1) `plans/AUDIT-VLT/findings/P1.1-access-resolver.md` существует, содержит 12 строк чеклиста с вердиктами и `file:line`; 2) все находки продублированы в `findings/REGISTER.md`; 3) `git status --porcelain -- packages docs e2e hocuspocus-server` пуст; 4) если писались repro — `/usr/bin/php8.4 vendor/bin/pest plans/AUDIT-VLT/artifacts/repro/P1.1` отработал и его вывод приложен; 5) `python3 "/home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-lint.py" plans/AUDIT-VLT` — без ошибок.

## 74

`plans/AUDIT-VLT/findings/P1.1-access-resolver.md` + строки в `findings/REGISTER.md` + (опционально) `plans/AUDIT-VLT/artifacts/repro/P1.1/`.

## 75

—

## 76

—

## 77

—

## 78

no

## 79

⬜ Not started

## 80

Проверить четыре реализации `HostRoleDriver` (Gate / Spatie / Database / Azgard), менеджер драйверов и пакет `permissions-azgard` на fail-closed-поведение, корректность `subjectsFor`/`override`/`canCreateInWorkspace` и отсутствие связности с хостом.

## 81

Шов ролей — единственный путь, которым внешняя система может ПОДНЯТЬ права (`granted = max(aclAllow, roleAllow)`); ошибка (fail-open при отсутствии драйвера, `override` → allow по умолчанию, `subjectsFor` возвращающий чужие субъекты) означает эскалацию привилегий. Нормы: централизованное разрешение (S1.6), per-subject-свёртка (S1.2), отсутствие связности с хостом (RAG:✅ 2026-07-10 — `findings/P0-embeddable-laravel.md` §E5).

## 82

`HostRoleDriverManager`, `Drivers/Permission/{Gate,Spatie,Database,Azgard}PermissionResolver`, `Contracts/Access/{HostRoleDriver,AzgardGuard,GrantAuthoritativeDriver}`, `Testing/{NullRoleDriver,FakeAzgardGuard}`, `Support/{GateConfig,SpatieConfig,AzgardConfig}`, `packages/permissions-azgard/src/*`, ключи `permission_drivers`/`gate`/`spatie`/`azgard` в конфиге.

## 83

сам алгоритм свёртки (P1.1); дизайн шва как расширяемости (P3.5); реальная интеграция с внешним AzGuard-пакетом (его репозиторий вне границ, D1).

## 84

`findings/P0-dms-security.md` §S1 · `findings/P0-embeddable-laravel.md` §E5 · `findings/P1.1-access-resolver.md` · `docs/adr/0007-pluggable-authorization.md`.

## 85

создаёт `plans/AUDIT-VLT/findings/P1.2-role-drivers.md`.

## 86

1) `plans/AUDIT-VLT/artifacts/finding-template.md` 2) `plans/AUDIT-VLT/findings/P1.1-access-resolver.md` 3) `docs/adr/0007-pluggable-authorization.md` 4) `packages/core/src/Access/HostRoleDriverManager.php` 5) `packages/core/src/Contracts/Access/HostRoleDriver.php` 6) `packages/core/src/Contracts/Access/AzgardGuard.php` 7) `packages/core/src/Contracts/Access/GrantAuthoritativeDriver.php` 8) `packages/core/src/Drivers/Permission/GatePermissionResolver.php` 9) `packages/core/src/Drivers/Permission/SpatiePermissionResolver.php` 10) `packages/core/src/Drivers/Permission/DatabasePermissionResolver.php` 11) `packages/core/src/Drivers/Permission/AzgardPermissionResolver.php` 12) `packages/core/src/Testing/NullRoleDriver.php` 13) `packages/core/src/Testing/FakeAzgardGuard.php` 14) `packages/core/src/Exceptions/Access/MissingHostRoleDriverException.php` 15) `packages/core/src/Support/AzgardConfig.php` 16) `packages/core/src/Support/GateConfig.php` 17) `packages/core/src/Support/SpatieConfig.php` 18) `packages/permissions-azgard/src/AzgardGuardAdapter.php` 19) `packages/permissions-azgard/src/NullAzgardGuard.php` 20) `packages/permissions-azgard/src/VaulterAzgardServiceProvider.php` 21) `packages/core/config/vaulter.php` (секции `permission_drivers`, `gate`, `spatie`, `azgard`)

## 87

Закрытый чеклист:
`C1` отсутствие/неверная конфигурация драйвера → исключение или отказ, НЕ «разрешить» (fail-closed); проверить `HostRoleDriverManager::driver()` и `MissingHostRoleDriverException`;
`C2` `NullRoleDriver` и `NullAzgardGuard` возвращают «ничего не разрешено» по всем методам (в т.ч. `override`, `canCreateInWorkspace`, `isSuperAdmin`);
`C3` `override()` каждого драйвера возвращает `Effect::Allow` ТОЛЬКО для явного супер-админа; нет пути, где неизвестный/гость получает Allow;
`C4` `subjectsFor()` не возвращает субъекты, к которым актор не принадлежит (в частности, у Azgard `subjectsFor = [actor]` — проверить, что ACL-гранты на группы/роли при этом не игнорируются молча, а это задокументированное ограничение);
`C5` `canCreateInWorkspace()` не даёт создать узел в чужом workspace (проверить, что `owner_type`/`owner_id` storage'а реально участвуют в проверке);
`C6` `DatabasePermissionResolver` строит запросы со скоупом (нет «голой» выборки по PK) — S3.2;
`C7` `SpatiePermissionResolver` и `GatePermissionResolver` не падают в fail-open при отсутствующих пакетах/гейтах (`Gate::has()`, наличие трейта);
`C8` `AzgardPermissionResolver` не имеет hard-dep на внешний пакет: `phpstan` зелёный при отсутствующем azguard (проверить `composer.json` core: azguard в `require` быть не должен);
`C9` карта `Ability → permission` (`config vaulter.azgard.permissions`) полна: КАЖДЫЙ кейс `Ability` имеет отображение; отсутствующий ключ не приводит к «разрешено»;
`C10` `permissions-azgard`: `AzgardGuardAdapter` не тянет `App\*` и не резолвит чужие ключи контейнера (E5.1/E5.3);
`C11` `GrantAuthoritativeDriver` — где используется, и не даёт ли он драйверу право переопределять deny-вето (пересечение с P1.1 C1);
`C12` тесты `packages/permissions-azgard/tests/*` покрывают fail-closed-путь (нет драйвера / нет guard'а).
Для `C1`, `C2`, `C3` — repro в `artifacts/repro/P1.2/` обязателен.

## 88

Fail-open — приоритетный класс находок: любое место, где отсутствие данных/конфига/зависимости приводит к «разрешено», = **Blocker**, даже если сценарий «маловероятен». ЗАПРЕЩЕНО оценивать драйверы по докблокам и README — только по телу методов, `file:line`. Ограничение Azgard (`subjectsFor=[actor]`, не энумерит роли) зафиксировано в памяти проекта как осознанное — по D5 сверить с `docs/adr/0007`: если задокументировано, находка = «ограничение задокументировано, риск: ACL на группы не работают под Azgard» (Major, если не задокументировано; Minor, если задокументировано). ЗАПРЕЩЕНО помечать 🟢 при незакрытом `C8`: проверка «phpstan зелёный без azguard» подтверждается фактическим прогоном `/usr/bin/php8.4 vendor/bin/phpstan analyse --no-progress --memory-limit=512M` и цитатой вывода, не рассуждением. Правки кода запрещены (D2).

## 89

1) `findings/P1.2-role-drivers.md` с 12 вердиктами и `file:line`; 2) вывод `/usr/bin/php8.4 vendor/bin/phpstan analyse --no-progress --memory-limit=512M` приложен (для `C8`); 3) находки в `REGISTER.md`; 4) `git status --porcelain -- packages docs e2e hocuspocus-server` пуст; 5) `plan-lint` без ошибок.

## 90

`plans/AUDIT-VLT/findings/P1.2-role-drivers.md` + строки REGISTER + `artifacts/repro/P1.2/`.

## 91

—

## 92

—

## 93

—

## 94

no

## 95

⬜ Not started

## 96

Проверить оба канала шэринга (grant-ACL и публичные share-link) и путь скачивания на confused-deputy, IDOR, утечку через bearer-URL, корректность TTL/отзыва и на то, что идентификатор ресурса берётся из проверенного токена.

## 97

Share-link — классический confused deputy: бэкенд действует привилегированным принципалом по предъявлении токена (RAG:✅ 2026-07-10 — `findings/P0-dms-security.md` §S2). Нормы: токен связывает resource+issuer+ops+expiry+nonce (S2.1); на потреблении проверяется, что **issuer до сих пор имеет права** (S2.2); id ресурса берётся из токена, не из URL (S2.4); TTL — минуты/часы (S4.1); URL — bearer, не логировать (S4.5).

## 98

`Sharing/{ShareResolver,ShareContext,ShareResolution,ShareResult}`, `Support/{ShareToken,RandomShareTokenGenerator}`, `Contracts/Sharing/ShareTokenGenerator`, `Http/Middleware/ResolveShareToken`, `Http/Controllers/Sharing/{PublicShareController,ShareController}`, `Actions/Sharing/*`, `Http/Resources/Sharing/*`, `Models/Sharing/NodeShare`, миграция `..._create_v_node_shares_table.php`, `Actions/Access/{Grant,Revoke}NodeAccessAction`, `Http/Controllers/Access/GrantController`, `packages/drive/src/Http/Controllers/DownloadController.php`, ключ конфига `share`.

## 99

свёртка ACL (P1.1); presigned-URL к S3 в контексте upload (P1.4); UI шэринга (P4.5).

## 100

`findings/P0-dms-security.md` §S2, §S3, §S4 · `findings/P0.3-attack-surface-map.md` · `plan.md` D15 (скачивание только через PHP) · `docs/adr/0013-two-sharing-channels.md`.

## 101

создаёт `plans/AUDIT-VLT/findings/P1.3-sharing-download.md`.

## 102

1) `plans/AUDIT-VLT/artifacts/finding-template.md` 2) `plans/AUDIT-VLT/findings/P0-dms-security.md` 3) `plans/AUDIT-VLT/findings/P0.3-attack-surface-map.md` 4) `plans/AUDIT-VLT/open-questions.md` 5) `docs/adr/0013-two-sharing-channels.md` 6) `packages/core/src/Sharing/ShareResolver.php` 7) `packages/core/src/Sharing/ShareContext.php` 8) `packages/core/src/Sharing/ShareResolution.php` 9) `packages/core/src/Sharing/ShareResult.php` 10) `packages/core/src/Support/ShareToken.php` 11) `packages/core/src/Support/RandomShareTokenGenerator.php` 12) `packages/core/src/Contracts/Sharing/ShareTokenGenerator.php` 13) `packages/core/src/Http/Middleware/ResolveShareToken.php` 14) `packages/core/src/Http/Controllers/Sharing/PublicShareController.php` 15) `packages/core/src/Http/Controllers/Sharing/ShareController.php` 16) `packages/core/src/Actions/Sharing/CreateShareLinkAction.php` 17) `packages/core/src/Actions/Sharing/RevokeShareLinkAction.php` 18) `packages/core/src/Models/Sharing/NodeShare.php` 19) `packages/core/database/migrations/2026_06_13_000014_create_v_node_shares_table.php` 20) `packages/drive/src/Http/Controllers/DownloadController.php` 21) `packages/core/config/vaulter.php` (секция `share`)

## 103

Закрытый чеклист:
`C1` токен генерируется CSPRNG (`random_bytes`/`Str::random`), длина ≥128 бит энтропии; НЕ `md5`/`sha1`/`uniqid`/`rand` (проверить `RandomShareTokenGenerator`);
`C2` токен хранится в БД в хешированном виде ИЛИ обоснованно в открытом (зафиксировать факт; открытое хранение + утечка дампа = Major);
`C3` сравнение предъявленного токена с сохранённым — constant-time (`hash_equals`), а не `==`/`===`;
`C4` node/resource берётся ИЗ записи, найденной по токену, а не из `{node}` в URL (S2.4); если контроллер принимает и токен, и node-id — проверить, что они сверяются;
`C5` при потреблении ссылки проверяется, что **issuer** (создатель) до сих пор имеет права на узел (S2.2) — отсутствие = Major (confused deputy);
`C6` `expires_at` проверяется на КАЖДОМ использовании, а не только при выдаче; истёкшая ссылка → 404/410, не 200;
`C7` отзыв (`RevokeShareLinkAction`) немедленно инвалидирует ссылку (нет кэша решения);
`C8` дефолтный TTL из `config vaulter.share` укладывается в S4.1 (≤72 ч); бесконечный TTL по умолчанию = Major;
`C9` `ShareResolution`/`ShareContext` ограничивает операции (read vs write) — одна ссылка не даёт и чтение, и запись без явного скоупа (S4.2);
`C10` публичный маршрут share не позволяет list/обход дерева за пределы разделённого узла (проверить `PublicShareController` + `ShareResolver` на обход `descendants`);
`C11` `DownloadController`: путь к объекту строится из `storage_key` блоба, а не из имени файла/пользовательского ввода; нет конкатенации пользовательской строки в путь (U4.1) и нет `..` (проверить `file:line`);
`C12` `DownloadController` авторизует КАЖДОЕ скачивание (Policy/AccessResolver/ShareToken) — S3.1; проверить и путь «скачивание конкретной версии файла»;
`C13` заголовки ответа скачивания: `Content-Disposition` экранирует имя файла (нет CRLF-инъекции в заголовок), `Content-Type` не берётся напрямую из пользовательских метаданных без whitelist;
`C14` токен не попадает в логи/`Log::`/exception-контекст (S4.5); грепнуть `token` в логирующих вызовах;
`C15` идентификаторы узлов, отдаваемые публично, не последовательные (S3.3) — при `id_type=id` (autoincrement) публичный share выставляет числовой id? зафиксировать факт;
`C16` (D15) **прямой раздачи из бакета нет**: ни один путь скачивания не возвращает presigned/публичный URL. Команда: `grep -rn "temporaryUrl\|presign\|->url(" packages/*/src --include=*.php`; каждый хит классифицировать. Публичный disk в `config vaulter.storage` (`visibility: public`) — тоже хит. Найденный путь прямой раздачи = Major, а при отсутствии TTL/скоупа — Blocker.
Для `C4`, `C5`, `C10`, `C11`, `C16` — repro в `artifacts/repro/P1.3/` обязателен.

## 104

Q3 закрыт решением D15: скачивание идёт **только** через `DownloadController`, авторизация — на каждом запросе; presigned-ссылки в download-пути не являются поддерживаемым режимом. Поэтому `C16` — не «зафиксировать факт», а проверка инварианта: любой обнаруженный путь прямой раздачи есть находка, даже если он выглядит удобной оптимизацией. Нормы S4 (TTL, одноразовость, отзыв) в этом item'е применяются ТОЛЬКО как критерий severity найденного отклонения, а не как требование к штатному пути. ЗАПРЕЩЕНО ослаблять чеклист под реализацию: если в коде нет проверки `C5`, вердикт `❌ fail` даже при аргументе «в нашей модели issuer всегда админ». ЗАПРЕЩЕНО чинить (D2) и класть repro в `packages/*/tests` (D6). ЗАПРЕЩЕНО помечать 🟢 при `C1`–`C3` без цитаты кода: криптографические утверждения подтверждаются строкой кода, а не выводом «используется Str::random, значит ок» — надо показать длину и источник энтропии. Расхождение с `docs/adr/0013` разбирается по D5.

## 105

1) `findings/P1.3-sharing-download.md` с 16 вердиктами и `file:line`; 2) `C16` содержит текст grep-команды и её фактический вывод; 3) находки в REGISTER; 4) `git status --porcelain -- packages docs e2e hocuspocus-server` пуст; 5) repro для C4/C5/C10/C11/C16 запущены через `/usr/bin/php8.4 vendor/bin/pest plans/AUDIT-VLT/artifacts/repro/P1.3`, вывод приложен; 6) `plan-lint` без ошибок.

## 106

`plans/AUDIT-VLT/findings/P1.3-sharing-download.md` + строки REGISTER + `artifacts/repro/P1.3/`.

## 107

—

## 108

—

## 109

—

## 110

no

## 111

⬜ Not started

## 112

Сверить реализацию resumable-загрузок (`TusUploadHandler`, `S3MultipartHandler`, `UploadSessionManager`, `StagedUpload`, `CommitUploadAction`, контроллеры и sweeper) с нормами U1–U7 из `findings/P0-upload-hardening.md`.

## 113

Поверхность загрузки — точка входа высшего риска (OWASP): offset-гонки портят файлы, доверие клиентскому checksum ломает CAS, `Upload-Metadata` даёт traversal, отсутствие лимитов = DoS, presign даёт SSRF (RAG:✅ 2026-07-10 — `findings/P0-upload-hardening.md`; нормы протокола tus 1.0.0 подтверждены через context7 `/websites/tus_io`).

## 114

`packages/drive/src/Uploads/**`, `packages/drive/src/Actions/UploadFileAction.php`, `packages/drive/src/Http/Requests/UploadFileRequest.php`, `packages/drive/src/Console/SweepUploadsCommand.php`, миграции `..._create_v_upload_sessions_table.php` и `..._create_v_upload_parts_table.php`, ключ конфига `uploads`, `packages/core/src/Schema/PanelTablesBuilder.php` (методы `defineUploadSessions`/`defineUploadParts`).

## 115

CAS-логика блоба после коммита (P1.5); квоты как бизнес-правило (только факт наличия проверки); фронтенд-загрузчик `packages/vue/src/composables/useUpload.ts` — только как источник фактов о заголовках, вердиктов по нему в этом item'е нет.

## 116

`findings/P0-upload-hardening.md` (U1–U7) · `findings/P0.2-rag-supplement.md` (T2, T5) · `findings/P0.3-attack-surface-map.md`.

## 117

создаёт `plans/AUDIT-VLT/findings/P1.4-uploads.md`.

## 118

1) `plans/AUDIT-VLT/artifacts/finding-template.md` 2) `plans/AUDIT-VLT/findings/P0-upload-hardening.md` 3) `plans/AUDIT-VLT/findings/P0.2-rag-supplement.md` 4) `packages/drive/src/Uploads/UploadSessionManager.php` 5) `packages/drive/src/Uploads/TusUploadHandler.php` 6) `packages/drive/src/Uploads/S3MultipartHandler.php` 7) `packages/drive/src/Uploads/StagedUpload.php` 8) `packages/drive/src/Uploads/Actions/CommitUploadAction.php` 9) `packages/drive/src/Uploads/Contracts/UploadProtocolHandler.php` 10) `packages/drive/src/Uploads/Http/Controllers/TusUploadController.php` 11) `packages/drive/src/Uploads/Http/Controllers/S3MultipartController.php` 12) `packages/drive/src/Uploads/Http/Requests/CreateUploadSessionRequest.php` 13) `packages/drive/src/Uploads/Models/UploadSession.php` 14) `packages/drive/src/Uploads/Models/UploadPart.php` 15) `packages/drive/src/Uploads/Repositories/UploadSessionStoreRepository.php` 16) `packages/drive/src/Uploads/Repositories/UploadPartStoreRepository.php` 17) `packages/drive/src/Console/SweepUploadsCommand.php` 18) `packages/drive/src/Actions/UploadFileAction.php` 19) `packages/core/database/migrations/2026_06_13_000016_create_v_upload_sessions_table.php` 20) `packages/core/database/migrations/2026_06_13_000017_create_v_upload_parts_table.php` 21) `packages/core/config/vaulter.php` (секция `uploads`)

## 119

Закрытый чеклист (нумерация соответствует нормам U):
`C1` (U1.1) `Tus-Resumable` присутствует на всех ответах tus-контроллера; запрос без него отвергается;
`C2` (U1.2) `PATCH` без `Content-Type: application/offset+octet-stream` → 415;
`C3` (U1.3/U2.4) `Upload-Offset` ≠ серверному → **409**, «прыжка вперёд» нет;
`C4` (U2.1/U2.2) `received_bytes` читается под блокировкой строки (`lockForUpdate`/транзакция) перед инкрементом; иначе гонка двух PATCH;
`C5` (U2.5) `received_bytes` монотонно растёт и увеличивается только ПОСЛЕ durable-записи в хранилище;
`C6` (U2.3) повтор с меньшим offset обрабатывается как идемпотентный повтор только при совпадении диапазона; иначе 409;
`C7` (U1.4) успешный PATCH → 204 + актуальный `Upload-Offset`;
`C8` (U1.5) `OPTIONS` анонсирует `Tus-Version`, `Tus-Extension`, `Tus-Max-Size` — либо отсутствие эндпоинта зафиксировано как отклонение от спеки;
`C9` (U1.6) checksum-расширение: реализовано ли; при реализации — 460 на несовпадение, 400 на неизвестный алгоритм; при отсутствии — заявлено ли отсутствие в `Tus-Extension`;
`C10` (U1.7) termination `DELETE` → 204 и удаляет staged-байты;
`C11` (U3.1) `Upload-Length` валидируется: отрицательные/нулевые/переполняющие отвергаются; потолок `uploads.max_bytes` применяется НА СОЗДАНИИ сессии, а не только на commit;
`C12` (U3.3) потолок размера одного PATCH-чанка применяется; `uploads.chunk_size` — это лимит или подсказка?
`C13` (U3.4) есть ли rate-limit/кап на число активных сессий на актора — отсутствие = Minor;
`C14` (U4.1/U4.2) `Upload-Metadata.filename` НЕ участвует в построении пути; storage-key генерируется сервером; traversal-последовательности отвергаются или невозможны по конструкции;
`C15` (U4.3/U4.4) whitelist ключей метаданных и лимиты длины полей;
`C16` (U5.1/U5.2) sha256 пересчитывается сервером по собранным байтам; клиентский checksum/ETag не является основанием для commit;
`C17` (U5.3/U5.4) сессия имеет `status` + `expires_at`; `SweepUploadsCommand` удаляет staged-байты, а не только строку БД; переход в complete строго при `received_bytes == total_size`;
`C18` (U5.5) валидация mime/magic-bytes — на собранном объекте (или зафиксировать отсутствие как Minor/Major по контексту);
`C19` (U6.1/U6.2) presign делает бэкенд с фиксированными bucket+key; клиент не влияет на bucket/key; нет пере-подписи пользовательского URL (SSRF);
`C20` (U6.3/U6.4) TTL presign ≤15 мин (или обоснованно больше); серверная запись связывает user/tenant/key/upload_id; повторная загрузка того же part_number контролируется (композитный PK `v_upload_parts` — проверить семантику «re-upload replaces»);
`C21` (U6.5/U6.6) границы part-size; осиротевшие multipart прерываются (`AbortMultipartUpload`) sweeper'ом или lifecycle-политикой;
`C22` (U6.7) complete переводит сессию в complete только при согласии по total bytes;
`C23` авторизация: `TusUploadController`/`S3MultipartController` проверяют право `Create`/`Edit` на КАЖДОМ запросе, включая `HEAD`/`PATCH`/`DELETE`, и что сессия принадлежит актору (IDOR на `upload_session_id`, S3.1);
`C24` (U7.1) события загрузки логируются/пишутся в activity (создание, abort, complete).
Для `C3`, `C4`, `C14`, `C16`, `C23` — repro в `artifacts/repro/P1.4/` обязателен.

## 120

Норма tus 1.0.0 — из `findings/P0-upload-hardening.md` §U1, подтверждена context7; ЗАПРЕЩЕНО ссылаться на «tus вроде бы разрешает» по памяти. Реализация «tus CORE без расширений» легальна — но тогда `C8`/`C9`/`C10` дают `n/a` **только** если отсутствие расширения явно объявлено в коде/доках; необъявленное отсутствие = Minor. `C4` — самая ценная проверка: показать конкретный `file:line` с транзакцией/локом ИЛИ доказать гонку repro-тестом (два конкурентных PATCH); отсутствие блокировки при мутации offset = **Blocker**. `C16` — сверить с памятью проекта: заявлено «server recompute sha256 с собранных байт»; подтвердить кодом, не доверять заявлению. ЗАПРЕЩЕНО чинить (D2); repro только в `artifacts/repro/P1.4/` (D6). ЗАПРЕЩЕНО помечать 🟢 при незакрытом `C4`/`C14`/`C16`. Если обнаружен traversal или доверие клиентскому хешу — Blocker, эскалация не требуется (аудит фиксирует, не чинит), но строка обязана попасть в `handoff.md` → Open risks.

## 121

1) `findings/P1.4-uploads.md` с 24 вердиктами и `file:line`; 2) repro для C3/C4/C14/C16/C23 запущены (`/usr/bin/php8.4 vendor/bin/pest plans/AUDIT-VLT/artifacts/repro/P1.4`), вывод приложен; 3) находки в REGISTER; 4) `git status --porcelain -- packages docs e2e hocuspocus-server` пуст; 5) `plan-lint` без ошибок.

## 122

`plans/AUDIT-VLT/findings/P1.4-uploads.md` + строки REGISTER + `artifacts/repro/P1.4/`.

## 123

—

## 124

—

## 125

—

## 126

no

## 127

⬜ Not started

## 128

Проверить content-addressed хранилище: корректность и безопасность дедупликации (scope), race-free commit, воскрешение tombstone, двухфазный GC и reconciler, конструкцию `storage_key`, а также tenant-изоляцию при `dedup.scope=global`.

## 129

Дедуп по хешу — прямая поверхность межтенантной утечки: при `scope=global` знание sha256 чужого файла даёт доступ к его блобу, если ссылка создаётся без проверки владения. Гонка commit/GC = потеря данных. `storage_key` участвует в пути на диске (U4.1–U4.2). RAG:✅ 2026-07-10 — `findings/P0-upload-hardening.md` §U4, §U5; `findings/P0-dms-security.md` §S3.

## 130

`packages/media/src/Cas/{BlobStore,Sha256ContentStore,FlysystemStorageDriver,StagedTempObject,BlobGarbageCollector,BlobReconciler}`, `packages/core/src/Cas/BlobLock.php`, `packages/core/src/Contracts/Cas/*`, `packages/core/src/Models/Cas/Blob.php`, `packages/core/src/Models/Drive/FileVersionBlob.php`, `packages/core/src/Repositories/Cas/BlobStoreRepository.php`, `packages/core/src/Enums/Cas/BlobStatus.php`, команды `GcBlobsCommand`/`ReconcileBlobsCommand`, `PanelTablesBuilder::defineBlobs`, миграция `..._create_v_blobs_table.php`, ключи конфига `dedup`, `gc`, `storage`, `cas`.

## 131

протокол загрузки (P1.4); производительность GC-запросов (P4.1); дизайн CAS как расширяемого шва (P3.5).

## 132

`findings/P0-upload-hardening.md` §U4/U5 · `findings/P0-dms-security.md` §S3 · `docs/adr/0006-content-addressed-blobs-gc.md` · `docs/adr/0012-dedup-scope.md`.

## 133

создаёт `plans/AUDIT-VLT/findings/P1.5-cas-blobs.md`.

## 134

1) `plans/AUDIT-VLT/artifacts/finding-template.md` 2) `plans/AUDIT-VLT/findings/P0-upload-hardening.md` 3) `docs/adr/0006-content-addressed-blobs-gc.md` 4) `docs/adr/0012-dedup-scope.md` 5) `packages/media/src/Cas/BlobStore.php` 6) `packages/media/src/Cas/Sha256ContentStore.php` 7) `packages/media/src/Cas/FlysystemStorageDriver.php` 8) `packages/media/src/Cas/StagedTempObject.php` 9) `packages/media/src/Cas/BlobGarbageCollector.php` 10) `packages/media/src/Cas/BlobReconciler.php` 11) `packages/core/src/Cas/BlobLock.php` 12) `packages/core/src/Contracts/Cas/BlobStore.php` 13) `packages/core/src/Contracts/Cas/ContentStore.php` 14) `packages/core/src/Contracts/Cas/StorageDriver.php` 15) `packages/core/src/Contracts/Cas/StagedObject.php` 16) `packages/core/src/Models/Cas/Blob.php` 17) `packages/core/src/Enums/Cas/BlobStatus.php` 18) `packages/core/src/Repositories/Cas/BlobStoreRepository.php` 19) `packages/core/src/Schema/PanelTablesBuilder.php` (метод `defineBlobs`) 20) `packages/core/database/migrations/2026_06_13_000009_create_v_blobs_table.php` 21) `packages/core/config/vaulter.php` (секции `dedup`, `gc`, `storage`, `cas`)

## 135

Закрытый чеклист:
`C1` sha256 считается сервером по **staged temp-файлу** (не по live-потоку, который клиент может подменить после хеширования) — подтвердить `file:line`;
`C2` `storage_key` = `<scope>/ab/cd/<sha256>`: ни один сегмент не приходит от пользователя; нет `..`, нет имени файла (U4.1);
`C3` `dedup` UNIQUE-ключ соответствует `config vaulter.dedup.scope` (`global` / `tenant` / `storage`) и миграция строит его из того же источника (`PanelTablesBuilder::defineBlobs`);
`C4` **tenant-изоляция**: при `scope=global` создание `FileVersionBlob`-ребра на существующий блоб не требует владения им, но и НЕ даёт доступа к чужому файлу (доступ решается ACL узла, не блобом). Доказать: путь скачивания идёт через node→file_version→blob и авторизуется на node (пересечение с P1.3 C12). Если существует путь «скачать блоб по sha256/id» — **Blocker**;
`C5` `commit` race-free: lock-first → find-or-create по dedup-ключу → resurrection tombstoned → promote только при created; проверить, что UNIQUE-нарушение обрабатывается (find-or-create ловит `QueryException`), а не роняет запрос;
`C6` `BlobLock` на PG использует `pg_advisory_xact_lock`; на других драйверах поведение деградирует корректно (UNIQUE как backbone), а не «молча без блокировки при отсутствии advisory»;
`C7` воскрешение tombstoned-блоба: статус переводится атомарно, и GC, работающий параллельно, не удалит его файл (проверить порядок: сначала статус, потом файл; и grace-окно `gc.grace_hours`);
`C8` двухфазный GC: между отметкой `pending` и физическим удалением проходит grace-окно; удаление файла происходит ТОЛЬКО если на момент удаления нет живых рёбер (повторная проверка);
`C9` `sweepPending` на PG использует SERIALIZABLE с retry на `40001`; на не-PG — эквивалентная защита или задокументированное ограничение;
`C10` `BlobReconciler` не удаляет файлы, которых нет в БД, без явного флага (иначе рассинхрон = потеря данных); проверить дефолт команды `vaulter:reconcile-blobs` (dry-run по умолчанию?);
`C11` `FlysystemStorageDriver`: запись во временный путь + атомарный `move`, а не запись сразу в финальный ключ (частичный файл под финальным ключом = порча CAS);
`C12` `StagedTempObject` удаляется во всех ветках (в т.ч. при исключении) — нет утечки temp-файлов; `tmp/`-префикс не пересекается с CAS-путями;
`C13` `ref_count` — кэш, а не источник истины (liveness = ребро); нигде решение «удалять» не принимается по `ref_count` в одиночку;
`C14` `Blob` не mass-assignable по `storage_key`/`sha256`/`tenant_id` из HTTP-слоя (проверить `$fillable`/`$guarded` и все места `create(`/`fill(` с request-данными);
`C15` `tenant_id` в dedup-ключе: при `scope=tenant` NULL-tenant не схлопывает разные тенанты в один ключ (в SQL `NULL != NULL` в UNIQUE → дубли; в PG UNIQUE допускает несколько NULL) — проверить, как это обработано;
`C16` секретов/путей нет в исключениях и логах CAS-слоя.
Для `C4`, `C5`, `C7`, `C11`, `C15` — repro в `artifacts/repro/P1.5/` обязателен.

## 136

`C4` — приоритет №1 этого item'а: любой HTTP-путь, отдающий содержимое блоба по его идентификатору/хешу без авторизации на узел, = **Blocker**. Начать с грепа контроллеров по `blob`/`sha256`/`storage_key`. ЗАПРЕЩЕНО делать вывод о race-free из докблока `BlobStore`: показать транзакцию, лок и обработку `QueryException` по `file:line`, либо воспроизвести гонку. `C15` (`NULL` в UNIQUE) — известные грабли SQLite/PG: если проверка требует прогона, писать repro-миграцию в `artifacts/repro/P1.5/`, НЕ трогая `packages/core/database`. Расхождение с `docs/adr/0006`/`0012` разбирается по D5. Правки кода запрещены (D2). ЗАПРЕЩЕНО помечать 🟢 при `C4`/`C5`/`C7` без repro или без цитаты кода с `file:line`.

## 137

1) `findings/P1.5-cas-blobs.md` с 16 вердиктами и `file:line`; 2) repro для C4/C5/C7/C11/C15 запущены, вывод приложен; 3) находки в REGISTER; 4) `git status --porcelain -- packages docs e2e hocuspocus-server` пуст; 5) `plan-lint` без ошибок.

## 138

`plans/AUDIT-VLT/findings/P1.5-cas-blobs.md` + строки REGISTER + `artifacts/repro/P1.5/`.

## 139

—

## 140

—

## 141

—

## 142

no

## 143

⬜ Not started

## 144

Проверить шов realtime-collab с обеих сторон: PHP (выпуск JWT, middleware входящих вызовов, persist Yjs-снапшота) и Node (`hocuspocus-server/`) — на алгоритм-pinning, привязку к комнате, enforcement `readOnly`/`exp`, timing-safe сравнение и защиту от replay.

## 145

Секрет HS256 подписывает и проверяет: его утечка или приём `alg:none` = выпуск любого токена (RAG:✅ 2026-07-10 — `findings/P0-collab-seam.md` §C1, §C2). Webhook-эндпоинты — фактически неаутентифицированные POST в состояние документа: без HMAC по сырому телу, timestamp-окна и constant-time сравнения они позволяют подделать collab-события (§C3). `readOnly` в Yjs не встроен и должен enforce'иться фильтрацией update-сообщений (§C5.3).

## 146

`packages/documents/src/Collaboration/*`, `packages/documents/src/Contracts/Collaboration/*`, `packages/documents/src/Http/Middleware/ResolveCollaborationCall.php`, `packages/documents/src/Http/Controllers/NodeCollaborationController.php`, `packages/documents/src/Actions/PersistYjsSnapshotAction.php`, `packages/documents/src/Data/CollaborationSessionData.php`, `packages/documents/src/Documents/CollaborationSessionService.php`, `hocuspocus-server/src/**`, ключ конфига `collaboration`, `packages/vue/src/nodeCollaboration.ts` (как источник фактов о передаче токена).

## 147

алгоритм авторизации на узле (P1.1); исходящие webhook пакета (P1.7); версионирование документов и prune (P4.1).

## 148

`findings/P0-collab-seam.md` (C1–C5 + «Проекция на vaulter») · `findings/P0.2-rag-supplement.md` (T1, T3) · `plan.md` D13 (collab — опциональная надстройка) · `docs/adr/0001-collaboration-transport.md`.

## 149

создаёт `plans/AUDIT-VLT/findings/P1.6-collab-seam.md`.

## 150

1) `plans/AUDIT-VLT/artifacts/finding-template.md` 2) `plans/AUDIT-VLT/findings/P0-collab-seam.md` 3) `plans/AUDIT-VLT/findings/P0.2-rag-supplement.md` 4) `plans/AUDIT-VLT/open-questions.md` 5) `docs/adr/0001-collaboration-transport.md` 6) `packages/documents/src/Collaboration/HocuspocusNodeCollaborationDriver.php` 7) `packages/documents/src/Collaboration/ReverbNodeCollaborationDriver.php` 8) `packages/documents/src/Collaboration/NodeCollaborationDriverManager.php` 9) `packages/documents/src/Collaboration/CollaborationCall.php` 10) `packages/documents/src/Collaboration/CollaborationCallResolver.php` 11) `packages/documents/src/Http/Middleware/ResolveCollaborationCall.php` 12) `packages/documents/src/Http/Controllers/NodeCollaborationController.php` 13) `packages/documents/src/Actions/PersistYjsSnapshotAction.php` 14) `packages/documents/src/Data/CollaborationSessionData.php` 15) `packages/documents/src/Documents/CollaborationSessionService.php` 16) `packages/documents/routes/documents.php` 17) `hocuspocus-server/src` (все файлы) 18) `hocuspocus-server/README.md` 19) `packages/core/config/vaulter.php` (секция `collaboration`) 20) `packages/vue/src/nodeCollaboration.ts`

## 151

Закрытый чеклист:
`C1` (C2.1/C2.2) верификация JWT задаёт **явный allow-list** `['HS256']`; `alg` из заголовка токена не используется; `none` отвергается. Проверить и PHP (`firebase/php-jwt`, `JWT::decode` третий аргумент), и Node (`jsonwebtoken`/`jose` опции `algorithms`);
`C2` (C1.2) секрет collab-JWT и секрет webhook — **разные** конфиг-ключи и разные значения по умолчанию; общий секрет = Major;
`C3` (C1.3/C1.5) секрет читается из `env()` внутри config, не хардкожен; дефолтное значение не является рабочим (пустой/`null` → отказ, а не «пустая строка как секрет»);
`C4` (C3.1/C3.2) HMAC входящего webhook считается по **сырому телу** (`$request->getContent()`), не по `$request->all()`; сравнение — `hash_equals`;
`C5` (C3.3/C3.4) подпись включает timestamp; запросы вне окна (±5 мин) отвергаются; есть защита от replay (nonce/message-id). Отсутствие timestamp-окна = Major;
`C6` `ResolveCollaborationCall` принимает «webhook_secret **ИЛИ** JWT» — доказать, что альтернатива не создаёт обход: путь JWT обязан требовать те же права, что путь секрета; если JWT-путь даёт больше — **Blocker**;
`C7` (C3.6) при провале верификации состояние документа не трогается (нет частичной записи до проверки);
`C8` (C4.1/C4.2) JWT несёт `room`, `sub`, `readOnly`, `iat`, `exp`; на стороне Node проверяется, что **запрошенная комната совпадает** с `room` из токена, а не берётся из query;
`C9` (C4.3) Node-сервер не выпускает токены, только верифицирует;
`C10` (C4.4) `Origin` валидируется при WebSocket-upgrade; в проде требуется `wss://`;
`C11` (C4.5) токен не передаётся в query-string (проверить `packages/vue/src/nodeCollaboration.ts` и Node-сервер); если передаётся — Major (утечка в логи);
`C12` (C5.1) `exp` проверяется на handshake **и** в течение соединения (таймер/периодическая проверка); отсутствие периодической проверки = Major;
`C13` (C5.3/C5.4) `readOnly` берётся из claims и enforce'ится **фильтрацией входящих update/SyncStep2** на Node-сервере; клиентский флаг не влияет; отсутствие серверной фильтрации при наличии `readOnly` в токене = **Blocker** (write под видом read);
`C14` `NodeCollaborationController` выдаёт токен только при `AccessResolver` ≥ `View`/`Comment`, а `readOnly=false` только при `Edit` (сверить с P1.1);
`C15` `room = channelPrefix.nodeId` — `nodeId` не позволяет инъекцию разделителя (id-стратегия `ulid`/`uuid7`/`id`; при `id_type=id` числовой id + разделитель `.` — проверить парсинг на Node-стороне);
`C16` `PersistYjsSnapshotAction` — транзакционный, append-only, с prune; не даёт записать снапшот в чужой узел (node берётся из проверенного токена/секрета, не из тела запроса — S2.4);
`C17` `ReverbNodeCollaborationDriver`: канал приватный, авторизация канала проверяет права на узел;
`C18` (D13) collab выключается конфигом: при выключенном фиче-флаге НЕ регистрируются маршруты `collab/*`, middleware `ResolveCollaborationCall`, драйверы и слушатели; базовое редактирование TipTap продолжает работать. Показать точку гейта (`file:line`); отсутствие гейта на любом из путей = Major;
`C19` (D13) отсутствие hard-dep на collab-стек: `firebase/php-jwt`, reverb/pusher, hocuspocus НЕ находятся в `require` пакета, который обязан работать без них (проверить `packages/core/composer.json` и `packages/documents/composer.json`; место им — `suggest`/`require-dev`). Обязательная зависимость там, где collab заявлен опциональным, = Major;
`C20` (D13) деградация без секрета: при `collaboration.webhook_secret = null` приложение поднимается и базовые маршруты работают; отказ происходит ТОЛЬКО на collab-путях и он fail-closed (пустой секрет не считается валидным). Пересечение с `C3`.
Для `C1`, `C6`, `C13`, `C16`, `C18` — repro обязателен: PHP-часть — Pest в `artifacts/repro/P1.6/`; Node-часть — если запуск невозможен, читается код и фиксируется `⚠ partial` с точной причиной.

## 152

Q4 закрыт решением D13: `hocuspocus-server/` и весь realtime-collab — **опциональная надстройка**, а не базовая поставка. Следствия для этого item'а: (1) находки по Node-серверу оцениваются как находки по опциональному компоненту — severity понижается на одну ступень ТОЛЬКО если компонент недостижим при выключенном фиче-флаге (это доказывает `C18`); если гейта нет и код collab исполняется всегда, понижения нет; (2) `C18`–`C20` проверяют сам факт разделения, и их провал — самостоятельная находка, независимая от криптографии. `C6` — самая ценная проверка: выписать полный путь обеих веток middleware и показать, какие данные каждая кладёт в `CollaborationCall`; расхождение прав = Blocker. ЗАПРЕЩЕНО считать `firebase/php-jwt` безопасным по умолчанию: показать фактический вызов `JWT::decode` и его третий аргумент (`file:line`); если библиотека версии, где allow-list обязателен — подтвердить фактом из `findings/P0.2-rag-supplement.md` (T1), не по памяти. Правки кода и Node-сервера запрещены (D2). ЗАПРЕЩЕНО помечать 🟢 при незакрытых `C1`/`C6`/`C13`. Если `C13` показывает отсутствие серверной фильтрации — Blocker + строка в `handoff.md` → Open risks.

## 153

1) `findings/P1.6-collab-seam.md` с 20 вердиктами и `file:line` (для Node — `hocuspocus-server/src/...:NN`); 2) repro для C1/C6/C13/C16/C18 запущены или помечены `⚠ partial` с причиной; 3) находки в REGISTER; 4) `git status --porcelain -- packages docs e2e hocuspocus-server` пуст; 5) `plan-lint` без ошибок.

## 154

`plans/AUDIT-VLT/findings/P1.6-collab-seam.md` + строки REGISTER + `artifacts/repro/P1.6/`.

## 155

—

## 156

—

## 157

—

## 158

no

## 159

⬜ Not started

## 160

Проверить исходящую webhook-подсистему (transactional outbox → relay → `DeliverWebhook`) на корректность подписи, защиту получателя от подделки, SSRF через пользовательский endpoint-URL, at-least-once-семантику и единственного владельца ретраев.

## 161

Endpoint-URL задаёт пользователь — это классический SSRF-примитив в внутреннюю сеть (`169.254.169.254`, `localhost`, приватные диапазоны); нормы подписи и timestamp-окна те же, что для входящих (RAG:✅ 2026-07-10 — `findings/P0-collab-seam.md` §C3). Ошибка «два владельца ретрая» (job retry + relay sweep) даёт дубли доставки.

## 162

`packages/core/src/Webhooks/{WebhookSigner,OutboxRelay,OutboxRecorder,RecordsOutbox}`, `packages/core/src/Jobs/Webhooks/DeliverWebhook.php`, `packages/core/src/Actions/Webhooks/*`, `packages/core/src/Http/Controllers/Webhooks/WebhookEndpointsController.php`, `packages/core/src/Http/Requests/Webhooks/*`, `packages/core/src/Models/Webhooks/{Outbox,WebhookEndpoint,WebhookDelivery}`, `packages/core/src/Console/{RelayOutboxCommand,RetryWebhooksCommand,PruneWebhooksCommand}`, `packages/core/src/Policies/WebhookEndpointPolicy.php`, `packages/drive/src/Webhooks/RecordsDriveOutbox.php`, миграции outbox/endpoints/deliveries, ключ конфига `webhooks`.

## 163

входящие collab-webhook (P1.6); очереди и производительность relay (P4.1).

## 164

`findings/P0-collab-seam.md` §C3 · `findings/P0-upload-hardening.md` §U6.1 (SSRF-принцип) · `findings/P0.3-attack-surface-map.md` · скилл `php:transactional-outbox-relay` (эталон семантики).

## 165

создаёт `plans/AUDIT-VLT/findings/P1.7-outbound-webhooks.md`.

## 166

1) `plans/AUDIT-VLT/artifacts/finding-template.md` 2) `plans/AUDIT-VLT/findings/P0-collab-seam.md` 3) `packages/core/src/Webhooks/WebhookSigner.php` 4) `packages/core/src/Webhooks/OutboxRelay.php` 5) `packages/core/src/Webhooks/OutboxRecorder.php` 6) `packages/core/src/Webhooks/RecordsOutbox.php` 7) `packages/core/src/Jobs/Webhooks/DeliverWebhook.php` 8) `packages/core/src/Actions/Webhooks/RegisterWebhookEndpointAction.php` 9) `packages/core/src/Actions/Webhooks/UpdateWebhookEndpointAction.php` 10) `packages/core/src/Http/Requests/Webhooks/RegisterWebhookEndpointRequest.php` 11) `packages/core/src/Http/Controllers/Webhooks/WebhookEndpointsController.php` 12) `packages/core/src/Models/Webhooks/WebhookEndpoint.php` 13) `packages/core/src/Models/Webhooks/Outbox.php` 14) `packages/core/src/Models/Webhooks/WebhookDelivery.php` 15) `packages/core/src/Console/RelayOutboxCommand.php` 16) `packages/core/src/Console/RetryWebhooksCommand.php` 17) `packages/core/src/Policies/WebhookEndpointPolicy.php` 18) `packages/core/src/Enums/Webhooks/WebhookEvent.php` 19) `packages/core/config/vaulter.php` (секция `webhooks`)

## 167

Закрытый чеклист:
`C1` (SSRF) URL эндпоинта валидируется при регистрации И перед доставкой: только `https` (или `http` по явному флагу), запрет приватных/loopback/link-local диапазонов и `169.254.169.254`, запрет редиректов на приватные адреса (проверить `allow_redirects` в HTTP-клиенте). Отсутствие валидации = **Blocker**;
`C2` (SSRF/DNS-rebinding) резолв хоста и проверка IP выполняются как можно ближе к запросу; либо зафиксировать как известное ограничение;
`C3` подпись исходящего запроса: HMAC-SHA256 по **сырому телу** + timestamp; заголовки задокументированы; алгоритм не `md5`/`sha1`;
`C4` секрет эндпоинта: генерируется CSPRNG, хранится хешированным или зашифрованным (`encrypted` cast), показывается получателю один раз; хранение в открытом виде = Major;
`C5` секрет не попадает в логи, ресурсы API (`WebhookEndpointResource`) и исключения;
`C6` outbox: запись события и бизнес-мутация в ОДНОЙ транзакции (иначе потеря событий); проверить `RecordsOutbox`/`RecordsDriveOutbox` и `ShouldDispatchAfterCommit`;
`C7` **единственный владелец ретрая**: либо job retry (`$tries`/`backoff`), либо relay-sweep — не оба одновременно (иначе двойная доставка); показать, кто владелец;
`C8` идемпотентность на стороне получателя обеспечена: у события есть стабильный уникальный id (`event_id`/`delivery_id`) в теле и/или заголовке;
`C9` backoff + DLQ (или `failed`-статус) существуют; бесконечный ретрай отсутствует;
`C10` `PruneWebhooksCommand` не удаляет недоставленные события;
`C11` таймаут HTTP-запроса задан (нет бесконечного ожидания); размер тела ответа ограничен;
`C12` авторизация CRUD эндпоинтов: `WebhookEndpointPolicy` покрывает index/show/store/update/destroy; актор не может зарегистрировать эндпоинт в чужом workspace/tenant (S3.1/S3.2);
`C13` mass-assignment: `WebhookEndpoint` не позволяет задать `secret`/`tenant_id`/`owner_id` из request-данных;
`C14` payload webhook не содержит содержимого документов/токенов доступа (утечка через сторонний endpoint).
Для `C1`, `C7`, `C12`, `C13` — repro в `artifacts/repro/P1.7/` обязателен.

## 168

`C1` проверяется чтением всего пути от `RegisterWebhookEndpointRequest` (правила валидации) до `DeliverWebhook` (фактический вызов HTTP-клиента). Правило `url` в Laravel **не защищает** от SSRF — если найдено только оно, это `❌ fail`, а не `pass`. `C7` — «два владельца ретрая» ищется грепом `$tries`, `backoff`, `release(`, `retry_after`, статусов outbox; если оба механизма присутствуют, показать, почему они не пересекаются, либо завести Major. Эталон семантики — скилл `php:transactional-outbox-relay`; ссылаться на его формулировки допустимо, на «общие знания об outbox» — нет. Правки кода запрещены (D2), repro — в `artifacts/repro/P1.7/` (D6). ЗАПРЕЩЕНО помечать 🟢 при незакрытом `C1`.

## 169

1) `findings/P1.7-outbound-webhooks.md` с 14 вердиктами и `file:line`; 2) repro для C1/C7/C12/C13 запущены, вывод приложен; 3) находки в REGISTER; 4) `git status --porcelain -- packages docs e2e hocuspocus-server` пуст; 5) `plan-lint` без ошибок.

## 170

`plans/AUDIT-VLT/findings/P1.7-outbound-webhooks.md` + строки REGISTER + `artifacts/repro/P1.7/`.

## 171

—

## 172

—

## 173

—

## 174

no

## 175

⬜ Not started

## 176

Закрыть остаточные security-оси: `env()` вне config, секреты в коде/логах/публикуемом конфиге и Docker-файлах, слабые дефолты, mass-assignment в моделях пакета, инъекции (SQL/`LIKE`), и маршруты без объектной авторизации из карты P0.3.

## 177

Опубликованный конфиг попадает в репозиторий хоста — секрет в его дефолте утекает в git (RAG:✅ 2026-07-10 — `findings/P0-collab-seam.md` §C1.5, `findings/P0-embeddable-laravel.md` §E7.2). `env()` вне config ломается при `config:cache` и молча даёт `null` (частый источник fail-open). Маршрут без объектной авторизации — прямой A01:2025 (§S3.1).

## 178

`packages/core/config/vaulter.php` и все `packages/*/config/*.php`, `packages/*/src/**` (греп по `env(`), все `Models/**` пакетов (fillable/guarded/casts), `e2e/docker/**`, `e2e/docker-compose.yml`, `hocuspocus-server/{Dockerfile,docker-compose.yml}`, `.mcp.json.example`, `testbench.yaml`, маршруты со значением `отсутствует` в колонке «Авторизация» из `findings/P0.3-attack-surface-map.md`.

## 179

уже покрытые швы (P1.1–P1.7) — здесь только то, что они не проверяли; supply-chain зависимостей (P4.4); CI-секреты (`.github/`) — вне границ D1, зафиксировать в «Не проверено».

## 180

`findings/P0.3-attack-surface-map.md` (подтаблица «маршруты без объектной авторизации») · `findings/P0-embeddable-laravel.md` §E7 · `findings/P0-collab-seam.md` §C1.

## 181

создаёт `plans/AUDIT-VLT/findings/P1.8-secrets-config-http.md`.

## 182

1) `plans/AUDIT-VLT/artifacts/finding-template.md` 2) `plans/AUDIT-VLT/findings/P0.3-attack-surface-map.md` 3) `plans/AUDIT-VLT/findings/P0-embeddable-laravel.md` 4) `packages/core/config/vaulter.php` (целиком) 5) `packages/documents/config/*.php` 6) `packages/drive/config/*.php` 7) `packages/media/config/*.php` 8) `packages/filament/config/*.php` 9) `packages/permissions-azgard/config/*.php` 10) `packages/core/src/Models/Concerns/HasExtraData.php` 11) `packages/core/src/Models/Concerns/HasVaulterKeys.php` 12) `packages/core/src/Http/Requests/VaulterFormRequest.php` 13) `e2e/docker-compose.yml` 14) `hocuspocus-server/Dockerfile` 15) `hocuspocus-server/docker-compose.yml` 16) `.mcp.json.example`

## 183

Закрытый чеклист:
`C1` `env()` вызывается ТОЛЬКО внутри `config/*.php`. Команда: `grep -rn "env(" packages/*/src | grep -v "/vendor/"` — любой хит = находка (Major: ломается при `config:cache`);
`C2` в публикуемом конфиге нет непустых дефолтов секретов (`webhook_secret`, `jwt secret`, ключи S3): дефолт — `null`/пустая строка + отказ при использовании, а не рабочее значение;
`C3` греп секретов в коде и Docker: `grep -rniE "(secret|password|token|api[_-]?key)\s*[:=]\s*['\"][^'\"]{8,}" packages/*/src packages/*/config e2e hocuspocus-server --include=*.php --include=*.ts --include=*.js --include=*.yml --include=Dockerfile` — каждый хит классифицировать (плейсхолдер / реальный секрет);
`C4` слабое хеширование: `grep -rn "md5(\|sha1(" packages/*/src` — использование для токенов/подписей = Major, для не-security ключей кэша = Nit;
`C5` `docker-compose.yml` (e2e и hocuspocus): нет захардкоженных паролей БД/секретов, порты не публикуются наружу без нужды, нет `privileged`, образы пинятся по тегу (не `latest`);
`C6` mass-assignment: у каждой модели пакета явно задан `$fillable` или `$guarded`; ЗАПРЕЩЁННЫЙ паттерн — `$guarded = []` в модели, доступной из HTTP-слоя. Проверить ВСЕ модели: `Node`, `Storage`, `File`, `FileVersion`, `Blob`, `FileVersionBlob`, `NodeGrant`, `NodeShare`, `Activity`, `Outbox`, `WebhookEndpoint`, `WebhookDelivery`, `UploadSession`, `UploadPart`, `NodeComment`, `NodeDocumentVersion`;
`C7` `HasExtraData`: JSON-колонка `extra_data` не позволяет протащить служебные ключи; при записи из HTTP фильтруется;
`C8` SQL/`LIKE`-инъекции: `grep -rn "whereRaw\|selectRaw\|DB::raw\|orderByRaw" packages/*/src` — каждый хит проверить на биндинги; поиск по имени узла экранирует `%`/`_` в `LIKE`;
`C9` сортировка/фильтрация из запроса не подставляется в `orderBy` без whitelist колонок;
`C10` каждый маршрут из подтаблицы «без объектной авторизации» (P0.3) получает вердикт: реально ли он публичный по замыслу (`share`, `collab-webhook`) или это дыра; дыра = Blocker/Major по достижимости;
`C11` `route_prefix` и `middleware` из конфига реально применяются; дефолтный `middleware: ['api']` не включает `auth` — задокументировано ли, что хост обязан добавить аутентификацию? Отсутствие такого предупреждения в README/reference = Major (fail-open по умолчанию у потребителя);
`C12` `VaulterFormRequest::authorize()` не возвращает `true` безусловно там, где авторизация не проверяется дальше в контроллере;
`C13` сообщения исключений (`Exceptions/**`) не раскрывают пути ФС, SQL и секреты;
`C14` `Activity`-фид не пишет секреты/токены в `context` (JSON).
Для `C10`, `C11`, `C12` — repro в `artifacts/repro/P1.8/` обязателен (feature-тест: анонимный запрос к маршруту).

## 184

Каждая проверка `C1`, `C3`, `C4`, `C6`, `C8` — это **конкретная grep-команда**, чей фактический вывод цитируется в отчёте; «прогрепал, ничего не нашёл» без вывода команды = дефект исполнения. Пустой вывод грепа — валидный `✅ pass`, но команда и её вывод (пустой) всё равно приводятся. `C11` — концептуально самая опасная находка этого item'а: если пакет по умолчанию монтирует маршруты без `auth`, а документация об этом молчит, потребитель получит открытое хранилище. Проверить `docs/reference/rest-api.md` и `README.md` на явное предупреждение; отсутствие = Major (не Minor). ЗАПРЕЩЕНО заводить находки по стилю кода — это ось P4. Правки запрещены (D2). ЗАПРЕЩЕНО помечать 🟢, если хоть один маршрут из подтаблицы P0.3 остался без вердикта в `C10`.

## 185

1) `findings/P1.8-secrets-config-http.md` с 14 вердиктами, где `C1`/`C3`/`C4`/`C6`/`C8` содержат текст команды и её фактический вывод; 2) число строк в `C10` = числу строк подтаблицы P0.3; 3) находки в REGISTER; 4) `git status --porcelain -- packages docs e2e hocuspocus-server` пуст; 5) `plan-lint` без ошибок.

## 186

`plans/AUDIT-VLT/findings/P1.8-secrets-config-http.md` + строки REGISTER + `artifacts/repro/P1.8/`.

## 187

—

## 188

—

## 189

—

## 190

no

## 191

⬜ Not started

## 192

—

## 193

—

## 194

—

## 195

—

## 196

—

## 197

—

## 198

—

## 199

—

## 200

—

## 201

—

## 202

—

## 203

—

## 204

—

## 205

—

## 206

—

## 207

⬜ Not started

## 208

—

## 209

—

## 210

—

## 211

—

## 212

—

## 213

—

## 214

—

## 215

—

## 216

—

## 217

—

## 218

—

## 219

—

## 220

—

## 221

—

## 222

—

## 223

⬜ Not started

## 224

—

## 225

—

## 226

—

## 227

—

## 228

—

## 229

—

## 230

—

## 231

—

## 232

—

## 233

—

## 234

—

## 235

—

## 236

—

## 237

—

## 238

—

## 239

⬜ Not started

## 240

—

## 241

—

## 242

—

## 243

—

## 244

—

## 245

—

## 246

—

## 247

—

## 248

—

## 249

—

## 250

—

## 251

—

## 252

—

## 253

—

## 254

—

## 255

⬜ Not started

## 256

—

## 257

—

## 258

—

## 259

—

## 260

—

## 261

—

## 262

—

## 263

—

## 264

—

## 265

—

## 266

—

## 267

—

## 268

—

## 269

—

## 270

—

## 271

⬜ Not started

## 272

—

## 273

—

## 274

—

## 275

—

## 276

—

## 277

—

## 278

—

## 279

—

## 280

—

## 281

—

## 282

—

## 283

—

## 284

—

## 285

—

## 286

—

## 287

⬜ Not started

## 288

—

## 289

—

## 290

—

## 291

—

## 292

—

## 293

—

## 294

—

## 295

—

## 296

—

## 297

—

## 298

—

## 299

—

## 300

—

## 301

—

## 302

—

## 303

⬜ Not started

## 304

—

## 305

—

## 306

—

## 307

—

## 308

—

## 309

—

## 310

—

## 311

—

## 312

—

## 313

—

## 314

—

## 315

—

## 316

—

## 317

—

## 318

—

## 319

⬜ Not started

## 320

—

## 321

—

## 322

—

## 323

—

## 324

—

## 325

—

## 326

—

## 327

—

## 328

—

## 329

—

## 330

—

## 331

—

## 332

—

## 333

—

## 334

—

## 335

⬜ Not started

## 336

—

## 337

—

## 338

—

## 339

—

## 340

—

## 341

—

## 342

—

## 343

—

## 344

—

## 345

—

## 346

—

## 347

—

## 348

—

## 349

—

## 350

—

## 351

⬜ Not started

## 352

—

## 353

—

## 354

—

## 355

—

## 356

—

## 357

—

## 358

—

## 359

—

## 360

—

## 361

—

## 362

—

## 363

—

## 364

—

## 365

—

## 366

—

## 367

⬜ Not started

## 368

—

## 369

—

## 370

—

## 371

—

## 372

—

## 373

—

## 374

—

## 375

—

## 376

—

## 377

—

## 378

—

## 379

—

## 380

—

## 381

—

## 382

—

## 383

⬜ Not started

## 384

—

## 385

—

## 386

—

## 387

—

## 388

—

## 389

—

## 390

—

## 391

—

## 392

—

## 393

—

## 394

—

## 395

—

## 396

—

## 397

—

## 398

—

## 399

⬜ Not started

## 400

—

## 401

—

## 402

—

## 403

—

## 404

—

## 405

—

## 406

—

## 407

—

## 408

—

## 409

—

## 410

—

## 411

—

## 412

—

## 413

—

## 414

—

## 415

⬜ Not started

## 416

—

## 417

—

## 418

—

## 419

—

## 420

—

## 421

—

## 422

—

## 423

—

## 424

—

## 425

—

## 426

—

## 427

—

## 428

—

## 429

—

## 430

—

## 431

⬜ Not started

## 432

—

## 433

—

## 434

—

## 435

—

## 436

—

## 437

—

## 438

—

## 439

—

## 440

—

## 441

—

## 442

—

## 443

—

## 444

—

## 445

—

## 446

—

## 447

⬜ Not started

## 448

—

## 449

—

## 450

—

## 451

—

## 452

—

## 453

—

## 454

—

## 455

—

## 456

—

## 457

—

## 458

—

## 459

—

## 460

—

## 461

—

## 462

—

## 463

⬜ Not started

## 464

—

## 465

—

## 466

—

## 467

—

## 468

—

## 469

—

## 470

—

## 471

—

## 472

—

## 473

—

## 474

—

## 475

—

## 476

—

## 477

—

## 478

—

## 479

⬜ Not started

## 480

—

## 481

—

## 482

—

## 483

—

## 484

—

## 485

—

## 486

—

## 487

—

## 488

—

## 489

—

## 490

—

## 491

—

## 492

—

## 493

—

## 494

—

## 495

Границы аудита: PHP-монорепо (`packages/*`), Vue-пакет (`packages/vue`), Docker-поверхность (`e2e/docker`, `hocuspocus-server/`), конфиг и миграции. Вне границ: `vendor/`, `node_modules/`, содержимое хост-проектов.

## 496

Аудит должен покрывать поставляемую поверхность пакета, а не транзитивные зависимости; supply-chain отдельной осью в P4.4. Alternatives: (а) только core — отвергнуто, дыры в collab-сервере и Docker остались бы невидимыми (pre-mortem R5.1); (б) включить FlexCRM целиком — отвергнуто, это чужой репозиторий, читаем его только в P2.4 как потребителя.

## 497

Фазы P0–P4 строго **read-only**: правки продакшн-кода запрещены; ремедиация — отдельный план, порождаемый в P5.2.

## 498

Смешение аудита и починки убивает независимость находок и порождает нераспутываемый дифф (pre-mortem R2.1). Alternatives: «чиним по ходу» — отвергнуто; «чиним Blocker сразу» — отвергнуто, срочность решает владелец, а не исполнитель item'а.

## 499

Каждый audit-item несёт **закрытый чеклист C1..Cn**, выведенный из RAG-выжимок P0; свободное ревью запрещено.

## 500

Дешёвая модель без эталона выдаёт пересказ кода вместо проверки инвариантов (pre-mortem R1.1). RAG:✅ 2026-07-10 — нормы U1–U7 / C1–C5 / S1–S4 / E1–E7 в `findings/P0-*.md`.

## 501

Схема находки фиксирована (`artifacts/finding-template.md`): `file:line` + доказательство + нарушенная норма + сверка с ADR + severity + уверенность. Реестр `findings/REGISTER.md` append-only.

## 502

Находка без воспроизводимого доказательства не переживает полгода (pre-mortem R1.2); сводный отчёт P5.1 строится машинно из REGISTER.

## 503

До заведения находки исполнитель обязан свериться с `docs/adr/*` и `docs/plan/00-master-plan.md`. Расхождение с сознательным ADR-решением классифицируется как «ADR устарел», а не как дефект.

## 504

Иначе аудит объявляет багом каждое осознанное отклонение от отраслевого дефолта (pre-mortem R3.2).

## 505

Repro-артефакты живут в `plans/AUDIT-VLT/artifacts/repro/<Pn.m>/`; попадание их в `packages/*/tests/` — дефект исполнения.

## 506

Иначе временные тесты остаются в пакете навсегда (pre-mortem R2.2).

## 507

План AUDIT-VLT не может быть закрыт `🟢 Done` при ≥1 находке severity **Blocker** без принятого решения владельца; допустимый исход — `🟠 Done with deviations`.

## 508

Честность доски: закрытый аудит с открытым Blocker'ом читается как «всё хорошо» (pre-mortem R4.3).

## 509

Severity-шкала — единая `Blocker / Major / Minor / Nit` из скилла `quality:artifact-review`; собственную шкалу не изобретаем.

## 510

Совместимость с остальными ревью экосистемы; RAG:✅ 2026-07-10 — скилл `quality:artifact-review` (SSOT таксономии ревью).

## 511

Research-этап разбит надвое: широкая RAG-верификация сделана **на этапе проектирования** (выжимки `findings/P0-{dms-security,upload-hardening,collab-seam,embeddable-laravel}.md`), а версионно-привязанные факты добиваются в P0.2 через context7.

## 512

Дешёвая модель не умеет формулировать поисковые запросы под нормы безопасности и галлюцинирует источники; при этом версии библиотек надо проверять на момент исполнения, а не проектирования. RAG:✅ 2026-07-10 (perplexity-web ×4, context7 `/websites/tus_io`).

## 513

Установка `plansDirectory: "plans"` в `.claude/settings.json` проекта.

## 514

`plan-protocol` §15: планы живут в проекте; ключ не был задан.

## 515

Целевой хост не один: аудит встраиваемости меряет **гибкость настройки**, а не совместимость с FlexCRM. P2.4 проверяет два эталонных сценария (FlexCRM + чистый Laravel/Testbench) и отдельной осью — конфигурируемость каждой точки стыковки (модели, таблицы, соединение, маршруты, middleware, morph-алиасы, драйверы). Закрывает Q1.

## 516

Ответ владельца 2026-07-10. Alternatives: (а) мерить только FlexCRM — отвергнуто, пакет получил бы «форму» одного хоста; (б) абстрактный чеклист без хостов — отвергнуто, интеграционные грабли ловятся только сквозным сценарием.

## 517

Минимальная поддерживаемая версия PHP — **8.4**. Матрица Laravel/Testbench/Filament не объявлена; её выработка — предмет находки P4.4, не блокер плана. Закрывает Q2.

## 518

Ответ владельца 2026-07-10. Согласуется с `require: php ^8.4` в корневом `composer.json`.

## 519

**Realtime-collab — опциональная надстройка, не часть базовой поставки.** Базовый пакет: `composer require` → встраиваемое документохранилище + редактирование TipTap без realtime, работает без Docker, без Node-сервиса и без дополнительных секретов. Совместное редактирование включается отдельным гайдом: поднять collab-сервис (Docker), опубликовать/зарегистрировать дополнительные провайдеры и ресурсы artisan-командами, включить фиче-флаг. Аудит проверяет отсутствие hard-wiring: P1.6 (`C18`–`C20`) — гейт, отсутствие hard-dep, деградация без секрета; P3.6 — качество шва «опциональная подсистема». Закрывает Q4.

## 520

Ответ владельца 2026-07-10 — продуктовое требование. Alternatives: (а) collab в базовой поставке — отвергнуто, тянет Node/Docker/секреты в каждый хост; (б) отдельный composer-пакет collab — не отвергнуто, а вынесено в предмет оценки P3.6 (это один из возможных вердиктов о том, как шов должен выглядеть).

## 521

Фаза P3 расширена item'ом **P3.6 — Опциональные подсистемы как надстройки**; общее число items плана 29 → 30.

## 522

D13 требует отдельной оси проверки: гейт фиче-флага, install-команда, публикация провайдеров/ресурсов, документированный путь включения, отсутствие обратной зависимости ядра от надстройки. Ни один существующий item эту ось не покрывал.

## 523

**Скачивание всегда идёт через PHP** (`DownloadController`), авторизация проверяется на каждом запросе. Прямая раздача из бакета (presigned/публичный URL) — не поддерживаемый режим. Нормы S4 (`findings/P0-dms-security.md`) применимы к presign в upload-пути (P1.4), но не к download. Закрывает Q3; проверяется P1.3 `C16`.

## 524

Ответ владельца 2026-07-10. Alternatives: (а) presigned-ссылки в бакет — отвергнуто: ссылка становится bearer-токеном, права по ней не перепроверяются, мгновенный отзыв невозможен (RAG:✅ 2026-07-10 — S4.3 «presigned URL не одноразовый по природе», S4.4 «нужны два уровня отзыва»); (б) гибрид по размеру файла — отвергнуто: правило переключения само становится поверхностью обхода авторизации.

## 525

2026-07-10 | issue-planner/opus | План создан: 6 фаз, 29 items. P0 и P1 детализированы, P2–P5 — скелеты.

## 526

2026-07-10 | issue-planner/opus | Design-time RAG: 4 выжимки в `findings/P0-*.md` (perplexity-web ×4 + context7 tus). Pre-mortem проведён → `findings/P0-premortem.md`, митигации в D2–D7.

## 527

2026-07-10 | issue-planner/opus | `plans/ACTIVE.md` создан, Active = AUDIT-VLT (ранее активного плана в репо не было).

## 528

2026-07-10 | issue-planner/opus | Ответы владельца на Q1–Q4 зафиксированы решениями D11–D13, D15; `open-questions.md` закрыт полностью.

## 529

2026-07-10 | issue-planner/opus | D14: добавлен item P3.6 (опциональные подсистемы как надстройки); P1.6 расширен проверками `C18`–`C20`, P1.3 — проверкой `C16`. Items плана: 30.

## 530


