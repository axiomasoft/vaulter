# VLT-CANON — Инвентарь текущей структуры под финальный канон (Часть 3)

**Дата:** 2026-07-11 · **Режим:** снимок current-state (кода не трогаем) · **Основание:** рекон main-loop.
Это ТЗ-объём Части 3. НЕ дублирует P4.9-инвентарь (тот — про ВНУТРЕННИЙ layer-canon A, УЖЕ исполнен
P4.9/P4.10 🟢). Здесь — уровень ВЫШЕ: топология пакетов, кросс-пакетные домены, catch-all-папки.

## 0. Базовая линия (что УЖЕ сделано — не переоткрывать)

| Веха | Статус | Артефакт |
|:--|:--|:--|
| Извлечение `vaulter-media` (CAS-движок) | 🟢 done | `docs/plan/p2-5-vaulter-media-extraction.md`, ADR-0008 |
| Извлечение `vaulter-drive` + `vaulter-documents` (Option B, schema-owner core) | 🟢 done (пакеты существуют) | `docs/plan/p2-5-option-b-topology.md`, ADR-0015 |
| Внутренний layer-canon A (Закон 1 layer-first + Закон 2 домен-под-namespace) | 🟢 done | ADR-0017, VLT-REMED P4.9/P4.10, arch-тесты |
| Реестр движков `DocumentHandler`/`HandlerRegistry`/`NodeHandlerResolver` + fallback | 🟢 exists | `packages/documents/src/Documents/*`, ADR VLT-HSEAM |
| Фронтовый шов `componentMap` по `handler.id`/`ui.component` | 🟢 exists (шов) | `packages/vue/src/components/VaulterDocumentNode.vue` |
| Дескриптор server→client (`HandlerDescriptor{id,capabilities,ui}` + `GET nodes/{node}/handler`) | 🟢 exists | `core/src/ValueObjects/Documents/{HandlerDescriptor,HandlerUi}.php` |

**Вывод:** «движки-редакторы как плагины» и «opaque handle → сервер резолвит → фронт получает дескриптор
компонента» — уже РЕАЛИЗОВАНЫ как швы. Часть 2 = ДОВЕСТИ (изолировать TipTap, переименовать в
kind-нейтраль, формализовать фронт-реестр/упаковку), а не строить.

## 1. Топология пакетов (current)

```
vaulter-core  (Vaulter\)                      kernel + schema-owner; НИ одной Vaulter-зависимости
  ▲    ▲    ▲    ▲    ▲
  │    │    │    │    └── vaulter-filament         (Vaulter\Filament\)          → core
  │    │    │    └─────── vaulter-permissions-azgard (Vaulter\Permissions\Azgard\) → core + azguard-core ^0.2
  │    │    └──────────── vaulter-documents        (Vaulter\Documents\)         → core   (движки/collab/export)
  │    └───────────────── vaulter-drive            (Vaulter\Drive\)             → core + media (файл/папка/upload)
  └────────────────────── vaulter-media            (Vaulter\Media\)             → core   (CAS-движок)

@vaulter/vue (npm)  → HTTP-клиент, PHP-сплит не влияет
```

Инвариант: все стрелки внутрь, к core; цикла нет (arch-тесты «0 ссылок core→feature»).

## 2. Цели Части 3 — по осям брифа

### 2.1 `permissions-azgard → azgard` (переименование пакета)
- **Сейчас:** dir `packages/permissions-azgard/`, composer `axioma-studio/vaulter-permissions-azgard`,
  namespace **`Vaulter\Permissions\Azgard\`** (двойной сегмент), содержимое: `Contracts/` +
  корневые `NullAzgardGuard`, `AzgardGuardAdapter`, `VaulterAzgardServiceProvider`.
- **Цель:** dir `packages/azgard/`, composer `axioma-studio/vaulter-azgard`, namespace `Vaulter\Azgard\`.
  Контракт-шов `Vaulter\Contracts\Access\AzgardGuard` (в CORE) — имя не трогаем (это core-контракт).
- **Риск:** LOW (пре-1.0, пакет — тонкий адаптер, консюмер = FlexCRM-host). Дисциплина
  `php:namespace-refactor-safety`. BC-запись в UPGRADE/CHANGELOG.

### 2.2 `media → drive?` (слияние — РАЗВИЛКА, в ## Обсуждение)
- **Сейчас:** `vaulter-media` = 2 папки (`Cas/` 6 движковых классов + `Console/` 2 команды), requires core.
  `vaulter-drive` requires core + **media** (upload/download коммитят в CAS через `Contracts\Cas\*`).
  `vaulter-documents` media НЕ требует (контент inline).
- **Вопрос owner:** слить media в drive (drive владеет CAS) или оставить standalone движок?
- **Предварительная рекомендация:** ОСТАВИТЬ (media — легитимный переиспользуемый CAS-движок,
  решение ADR-0008/topology T-анализа; слияние не даёт продуктовой выгоды, а ломает «media = чистый
  движок»). Финал — после research + в ## Обсуждение плана.

### 2.3 Catch-all / grab-bag папки → канон
| Папка | Содержимое | Проблема | Кандидат-канон |
|:--|:--|:--|:--|
| `documents/src/Documents/` | `DocumentHandlerRegistry`, `NodeHandlerResolver`, `DocumentFileDescriptorFactory`, `CollaborationSessionService`, `NodeExportService` + `Handlers/{DownloadOnly,Preview,TipTap,Univer}` | смесь «реестр движков» (инфра) + «драйверы» (движки) + сервисы под одной доменной папкой; имя `Documents/Documents` тавтологично | ЭТО дом Части 2 — развести: реестр/резолвер → `Engines/` (или в core, Q1), драйверы → `Engines/Handlers/` или per-driver, сервисы → слои |
| `drive/src/Uploads/` (flat) | `MagicByteSniffer`, `S3MultipartHandler`, `StagedUpload`, `TusUploadHandler`, `UploadModels`, `UploadSessionManager` | 6 плоских service-классов в доменной папке (P4.10 оставил как domain-service folder) | развести по роли: `*Handler`/`*Manager` → домен-сервис ок; `MagicByteSniffer`/`StagedUpload` → `Support/Uploads`; `UploadModels` — helper |
| `documents/src/Support/` | `DocumentContentHasher`, `TipTapRenderer`, `Concerns/HasTiptapExtensions` | TipTap-специфика в Support (утечки L2 из VLT-HSEAM живут тут) | `TipTapRenderer`/`HasTiptapExtensions` — внутрь TipTap-драйвера; `DocumentContentHasher` — движок-нейтраль |
| core `Webhooks/` | outbox/relay/retry/prune | легитимная домен-сервис-папка (ADR-0017 allow) | сверить: канон ок, вероятно no-op |

### 2.4 Access-домен
- **core `Access/`:** `AccessResolver`, `Abilities/`, `HostRoleDriverManager`, `ValueObjects/` — ACL-движок,
  легитимная домен-сервис-папка (ADR-0017). Node-coupled → остаётся в core (topology T1). Вероятно no-op/сверка.
- **`documents/src/Access/HostUserResolver`:** 1-классовая стрелка `Access/` в documents — стрей.
  Решение: свернуть в подходящий слой documents ЛИБО подтвердить как легитимный домен-сервис.

### 2.5 Uploads-инверсия
- P4.10 распустил `Uploads/{Actions,Models,Repositories,Http,Enums,Data,Contracts}` в слои (`Actions/Uploads` …)
  — Закон 1 исполнен. Остался domain-service folder `Uploads/` с 6 плоскими классами (см. 2.3).
- «Инверсия» брифа = финальное решение по этим 6 сервисам (роль-based раскладка, см. 2.3).

## 3. VLT-HSEAM tail (поглощается Частью 2)
5 утечек TipTap (L1–L5), политика эволюции capabilities, DX-каркас + 2 owner-развилки Q1 (движок реестра →
core) / Q2 (`yjs_state`→`crdt_state`). Детали — `plans/VLT-HSEAM/{plan.md §5-§7, root/adr-file-handler-seam.md}`.
VLT-CANON поглощает VLT-HSEAM целиком (после переноса — пометить VLT-HSEAM superseded).

## 4. Наименования-развилки (в ## Обсуждение плана)
- Контракт: `DocumentEngine` (язык брифа, «движок-редактор») vs `FileHandler` (VLT-HSEAM D3, kind-нейтраль,
  покрывает image/pdf/download, не только редактируемые доки). Спор реальный — рецензия в плане.
- Фронт-пакет: единый `@vaulter/vue` (сейчас) vs `@vaulter/view` vs per-engine npm-пакеты vs `core+engines`.
  Решается по research Части 1 (Filament/Nova/Statamic asset-дистрибуция).
