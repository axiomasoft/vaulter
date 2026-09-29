# Migration inventory archive

## 1

VLT-HSEAM

## 2

Editor-agnostic шов файловых обработчиков: закрыть утечки TipTap до 1.0

## 3

0.1.0

## 4

⛔ Superseded — see VLT-CANON P3.3 / ADR-0018

## 5

Executable Master Plan

## 6

opus (fable)

## 7

2026-07-10

## 8

/home/vostrikov/projects/packages/vaulter (axioma-studio/vaulter)

## 9

vaulter-core, vaulter-documents, vaulter-drive, vaulter-filament, @vaulter/vue

## 10

phase-first

## 11

opus (P0.1–P0.2 — публичные контракты) · sonnet (P0.3–P0.5)

## 12

Dmitry Vostrikov

## 13

repo:plans/VLT-HSEAM

## 14

⬜ Not started

## 15

Переименовать публичную поверхность шва в kind-нейтральную: namespace
`Vaulter\Contracts\Documents\*` → `Vaulter\Contracts\Handlers\*`; `DocumentHandler` →
`FileHandler`; `DocumentCapabilities` → `HandlerCapabilities`; сопутствующие VO
(`FileDescriptor`, `HandlerUi`, `HandlerDescriptor`) — в `Vaulter\ValueObjects\Handlers\*`.
`DocumentContent`/`OpaqueDocumentContent` НЕ трогать (они реально про документный контент).

## 16

Хендлер описывает любой файл (image/*, xlsx, cad), не только документ; пост-1.0
rename = major (D3, RAG:✅ 2026-07-10 рекон L5).

## 17

rename интерфейсов/VO/namespace + все use-сайты по монорепо (core,
documents, drive, filament); config-ключ `vaulter.handlers` (остаётся); докблоки контрактов.

## 18

движок реестра (P0.3); exporter (P0.2); схема/richText (P0.4); vue-пакет
(строковые id хендлеров не меняются — клиент не задет).

## 19

`findings/P0-recon-core-seams.md` (карта use-сайтов — отправная точка).

## 20

`packages/core/src/Contracts/Documents/{DocumentHandler,HandlerRegistry,NodeHandlerResolver}.php`,
`packages/core/src/ValueObjects/Documents/{DocumentCapabilities,FileDescriptor,HandlerUi}.php`,
`packages/documents/src/Documents/**`, `packages/documents/src/Enums/Capability.php`,
все use-сайты (grep по FQN).

## 21

`findings/P0-recon-core-seams.md`; skill `php:namespace-refactor-safety`;
`packages/core/src/Contracts/Documents/DocumentHandler.php` (текущие докблоки — сохранить
содержание H.5-инвариантов при переносе).

## 22

Якорить замену на FQN, не на голый токен (`namespace-refactor-safety`);
hard rename без alias-фазы (пакет @dev — D3); строковые id хендлеров ('tiptap', 'univer',
'download', 'preview') НЕ менять — это wire-контракт клиента; `Vaulter::registerHandler()`
сигнатура обновляется на `FileHandler|string`.

## 23

Сохранить докблоки-инварианты (H.5: «one-class + register», «registry
string keys are the source of truth», SemVer-заморозка хендлеров) в новых файлах; phpstan max
без новых ignore; НЕ вводить обобщённых `HandlerInterface`-иерархий — плоский интерфейс как
сейчас; никаких «переименовать заодно» вне списка Scope.

## 24

`vendor/bin/pint --test` + `vendor/bin/phpstan analyse` + полный pest через
`/usr/bin/php8.4` зелёные; `vaulter-api-surface`-отчёт: удалённые/добавленные FQN соответствуют
ровно списку Scope; grep по репо: `Contracts\\Documents\\(DocumentHandler|HandlerRegistry|NodeHandlerResolver)` = 0 вхождений.

## 25

Коммит(ы) rename; отчёт api-surface в Completion Notes.

## 26

—

## 27

—

## 28

—

## 29

no

## 30

⬜ Not started

## 31

`DocumentExporter::export(TipTapContent $content)` → `export(DocumentContent
$content)`; классы `TipTapContent`, `TiptapExtensions` (attribute), `InvalidTipTapContentException`
перенести из `packages/core/src` в `packages/documents/src` (namespace `Vaulter\Documents\...`).

## 32

Экспортёр не-TipTap-хендлера не может реализовать core-контракт — самая острая утечка
(L1/L2, D5; RAG:✅ 2026-07-10 рекон).

## 33

сигнатура контракта; move 3 классов + их тестов; реализация экспортёров
documents (html/markdown) — внутри может стоять instanceof-даункаст к TipTapContent с внятным
исключением для чужого контента; `Vaulter::exporter()`/`registerExporter()` — проверить типы.

## 34

новые форматы экспорта; конвертация между форматами (не в скоупе плана).

## 35

`findings/P0-recon-core-seams.md` (L1/L2); результат P0.1 (новый namespace).

## 36

`packages/core/src/Contracts/Documents/DocumentExporter.php` (после P0.1 —
`Contracts/Handlers/` или остаётся в Documents-подпространстве — решить по факту P0.1),
`packages/core/src/ValueObjects/Documents/TipTapContent.php`,
`packages/core/src/Attributes/TiptapExtensions.php`,
`packages/core/src/Exceptions/Documents/InvalidTipTapContentException.php`,
`packages/documents/src/Export/**`, тесты обоих пакетов.

## 37

`packages/documents/src/Export/` (текущие экспортёры); тесты
TipTapContent в core.

## 38

Контракт принимает `DocumentContent`; реализация, поддерживающая
только TipTap, кидает типизированное исключение (`UnsupportedContentException`-стиль), НЕ
молча деградирует; тесты переезжают вместе с классами (test-isolation не ломать).

## 39

Не вводить generic-параметризацию контракта (phpstan-generics на публичном
интерфейсе = DX-налог); сообщение исключения — какой exporter, какой content-класс получен,
какой ожидался; писать по `php:code-style-spatie`.

## 40

Гейт монорепо зелёный; `vaulter-api-surface`: изменение сигнатуры контракта
зафиксировано; grep `TipTapContent` в `packages/core/src` = 0.

## 41

Коммит(ы); отчёт api-surface; ревью `vaulter-reviewer` (ось BC).

## 42

—

## 43

—

## 44

—

## 45

no

## 46

⬜ Not started

## 47

[NEEDS CLARIFICATION: owner-решение Q1 (plan.md §7) — вариант A (движок в core)
или B (оставить в documents); при B item закрывается как ⛔ Skipped by decision]. При A:
перенести `DocumentHandlerRegistry` (→ `FileHandlerRegistry`), `DownloadOnlyHandler`,
`PreviewHandler`, `NodeHandlerResolver`-реализацию и `Capability`-enum в core; documents
оставляет TipTapHandler/UniverHandler/collab и биндит их через tag/config.

## 48

Drive-only хост должен получать preview/download-descriptor'ы без vaulter-documents;
«tiptap — одно из расширений» становится буквальным (Обсуждение Q1, вариант A рекомендован).

## 49

move классов + тестов; биндинги SP core (движок = singleton в
`packageRegistered()`); documents SP теряет `bind(HandlerRegistry, ...)`, оставляет tag своих
хендлеров; докблок H.5 в `HandlerRegistry`-контракте обновить (движок теперь в core).

## 50

изменение алгоритма резолва (mime → ext → capability → priority — не трогать);
мемоизация — не трогать.

## 51

решение D9; результаты P0.1.

## 52

`packages/documents/src/Documents/DocumentHandlerRegistry.php`,
`packages/documents/src/Documents/Handlers/{DownloadOnlyHandler,PreviewHandler}.php`,
`packages/documents/src/Documents/NodeHandlerResolver.php`,
`packages/documents/src/Enums/Capability.php`, оба ServiceProvider, тесты.

## 53

`packages/documents/src/VaulterDocumentsServiceProvider.php:54-100`;
`packages/core/src/VaulterServiceProvider.php`.

## 54

Биндинги реестра — в `packageRegistered()` (register-vs-boot!);
feature-gate 'spreadsheets' для UniverHandler остаётся в documents; поведение «host без
documents» меняется с fail-loud на «Download/Preview из коробки» — зафиксировать это в
CHANGELOG-заметке как поведенческое изменение.

## 55

Никакой новой публичной поверхности сверх переносимой; тест «drive-only
хост (без documents SP) получает download-descriptor» — обязателен (Testbench без
VaulterDocumentsServiceProvider); TipTapHandler НЕ переносить в core ни при каких условиях.

## 56

Гейт зелёный; новый testbench-тест drive-only проходит; api-surface отчёт.

## 57

Коммит(ы); тест drive-only; CHANGELOG-заметка.

## 58

—

## 59

—

## 60

—

## 61

no

## 62

⬜ Not started

## 63

(a) `PanelTablesBuilder::defineNodeDocumentVersions`: убрать `->default('tiptap')`
у `format` — writer проставляет явно; (b) `HandlerCapabilities` (ex-DocumentCapabilities):
`richText` default `true` → пересмотреть на нейтральный (см. Implementation Rules); (c)
комментарии схемы «TipTap/ProseMirror snapshot» → «handler-native snapshot (discriminated by
format)»; (d) `yjs_state` — [NEEDS CLARIFICATION: owner-решение Q2 (plan.md §7); рекомендация —
оставить имя, тогда пункт (d) = только докблок].

## 64

Молчаливый default 'tiptap' маркирует чужой контент как tiptap = порча данных при
забытом format; `richText=true` — «историческая форма» как дефолт core-VO (L3/L4; RAG:✅
2026-07-10 рекон + таксономия урок 6).

## 65

builder схемы; write-path документных версий (все места, создающие
version-строки, — проставить format от хендлера); VO + его потребители (`PersistYjsSnapshotAction`
ветвится по richText); фикстуры тестов.

## 66

переименование колонок (если Q2=A); новые capability-оси (D8 — не добавляем).

## 67

решение D10 (Q2); результаты P0.1.

## 68

`packages/core/src/Schema/PanelTablesBuilder.php:550-565`,
`packages/core/src/ValueObjects/Documents/DocumentCapabilities.php` (пост-P0.1 путь),
`packages/documents/src/Actions/PersistYjsSnapshotAction.php`,
`packages/documents/src/Repositories/NodeDocumentVersionStoreRepository.php`, тесты/фикстуры.

## 69

`findings/P0-recon-core-seams.md` (L3/L4); write-path: grep
`format` по `packages/documents/src`.

## 70

Вариант для richText: дефолт `false` у VO + `TipTapHandler`
явно ставит `true` (semantics: «rich-text — opt-in свойство хендлера, не мира»); проверить,
что UniverHandler уже ставит `false` явно — тогда флип дефолта безопасен; schema-изменение
пре-1.0 — правится сама миграция 000013 (хостов нет), НЕ новая миграция.

## 71

Ни одного write-path, способного вставить version-строку без format
(NOT NULL без default — пусть БД ловит); комментарии схемы — на английском, как весь builder;
тест: строка с format='univer' не проходит TipTap-валидацию и не проецируется.

## 72

Гейт зелёный; sqlite+pg миграции проходят; grep `default('tiptap')` = 0.

## 73

Коммит(ы); Completion Notes с решением по richText.

## 74

—

## 75

—

## 76

—

## 77

no

## 78

⬜ Not started

## 79

(a) Задокументировать политику эволюции capabilities (D6) в докблоке
`HandlerCapabilities` + `docs/` (клиенты игнорируют незнакомые ключи `toArray()`; оси —
аддитивно с дефолтами; fallback-инвариант); (b) написать гайд «Собственный обработчик файлов»
(`docs/extending-handlers.md`): интерфейс → реализация → регистрация (3 канала) → priority →
ui-descriptor, со skeleton-примером пакета; (c) опционально `AbstractFileHandler`-база с
дефолтами (priority 0, extensions [], capabilities downloadOnly) — если после (b) видно, что
она сокращает boilerplate; (d) финальная сверка: `vaulter-api-surface` diff всей ветки +
полный гейт + перенос ADR из `plans/VLT-HSEAM/root/` в `docs/adr/`.

## 80

Каркас для сторонних разработчиков — вторая половина запроса владельца; политика
эволюции — страховка BC на годы (D6, D8; RAG:✅ 2026-07-10 таксономия уроки 1-4).

## 81

докблоки, docs, опц. Abstract-база + её тест, финальный api-surface, ADR→docs.

## 82

реализация новых хендлеров; изменения алгоритма резолва.

## 83

результаты P0.1–P0.4; `findings/P0-rag-capability-taxonomy.md` (уроки BC);
`root/adr-file-handler-seam.md`.

## 84

`packages/core/src/ValueObjects/Handlers/HandlerCapabilities.php` (докблок),
`docs/extending-handlers.md` (новый), `docs/adr/` (перенос ADR), опц.
`packages/core/src/Handlers/AbstractFileHandler.php` + тест.

## 85

финальные версии контрактов после P0.1–P0.4; `docs/` (стиль существующих доков).

## 86

Гайд — по реальному коду (каждый сниппет компилируем против текущих
контрактов); Abstract-база — final НЕ ставить (её растят наследованием), но методы-дефолты
типизированы строго; ADR при переносе получает номер по нумерации `docs/adr/`.

## 87

Гайд пишется как для внешнего разработчика без доступа к монорепо
(vendor-перспектива: composer require + SP + config); писать по `general:writing-style`
(рус. внутр. доки — но `docs/` пакета публичные и англоязычные — следовать языку существующих
`docs/adr/*`); не обещать в доках нереализованных осей (lossyEdit/convert — упомянуть как
«зарезервированное направление», не как API).

## 88

Гейт зелёный; api-surface diff ветки = ровно сумма P0.1–P0.4; линтер маркдауна
если есть; сниппеты гайда собраны в testbench-тест-компиляцию (или вручную проверены — отметить).

## 89

docs/extending-handlers.md; ADR в docs/adr/; финальный отчёт api-surface;
Phase Handoff.

## 90

—

## 91

—

## 92

—

## 93

no

## 94

Реестр обработчиков НЕ строить заново — он существует и корректен; скоуп = закрытие утечек

## 95

Рекон: `findings/P0-recon-core-seams.md` (RAG:✅ 2026-07-10 рекон монорепо)

## 96

Единица стороннего расширения = composer-пакет, биндящийся в core-контракты; НЕ каталог в ядре

## 97

RAG:✅ 2026-07-10 Filament plugins / Flysystem adapters / Nextcloud apps (`P0-rag-laravel-registry-patterns.md`)

## 98

Kind-нейтральный нейминг до 1.0: `Contracts/Documents/*`→`Contracts/Handlers/*`, `DocumentHandler`→`FileHandler`, `DocumentCapabilities`→`HandlerCapabilities`

## 99

Хендлер описывает любой файл; пост-1.0 rename = major (RAG:✅ 2026-07-10 рекон L5)

## 100

Публичный контракт остаётся узким bespoke-интерфейсом; `Illuminate\Support\Manager` НЕ вводить

## 101

RAG:✅ 2026-07-10 statefulness/$app-coupling/__call (`P0-rag-laravel-registry-patterns.md`)

## 102

`DocumentExporter::export()` принимает опаковый `DocumentContent`; TipTap-специфика — в реализациях documents

## 103

Утечка L1; пост-1.0 = major (RAG:✅ 2026-07-10 рекон)

## 104

Политика эволюции capabilities: открытое множество, аддитивные оси с дефолтами, клиенты игнорируют незнакомые ключи; fallback-хендлер = инвариант «новый тип ≠ breaking»

## 105

RAG:✅ 2026-07-10 WOPI ignore-unknown / Notion unsupported / OO аддитивный рост (`P0-rag-capability-taxonomy.md`)

## 106

Динамический резолв mime→handler сохранить; handler-id на Node НЕ персистить

## 107

RAG:✅ 2026-07-10 WOPI/NC/Drive резолвят динамически; персист протухает (`P0-rag-editor-extension-models.md` п.9)

## 108

Хендлеры под будущие типы НЕ пре-имплементировать; готовность = открытый контракт + fallback + DX-каркас (Abstract-база + skeleton-гайд)

## 109

YAGNI; UniverHandler уже доказал шов (RAG:✅ 2026-07-10 таксономия, урок 3)

## 110

2026-07-10 | issue-planner/opus | План создан: design-сессия, 4 RAG-фронта в findings/, ADR в root/, P0 детализирован

## 111


