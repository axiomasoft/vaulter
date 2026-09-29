<!-- plan-projection
projection: status-view
projection_version: v1
source_scope: plan core + phases + journal
through: 1970-01-01
inputs_sha256: 9fd02899214c8c91224ecb3820220aa018add7e69f5bbd0452583d31fdf027f7
generated_by: task plan-views status
-->

# Статус плана

> Не править руками: это генерируемый вид.

## 0. Meta

| Поле | Значение |
|:--|:--|
| Version | 0.1.0 |
| Status | ⬜ Not started |
| Last Updated | 1970-01-01 |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P0 | Research & Baseline | 0/3 | ⬜ Not started |
| P1 | Безопасность | 0/8 | ⬜ Not started |
| P2 | Встраиваемость и DX | 0/5 | ⬜ Not started |
| P3 | Гибкость и расширяемость | 0/6 | ⬜ Not started |
| P4 | Качество и производительность | 0/5 | ⬜ Not started |
| P5 | Синтез и ремедиация | 0/3 | ⬜ Not started |

## Phase P0

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P0.1 | Baseline-инвентаризация репозитория и тулинга | ⬜ Not started | — |
| P0.2 | RAG-добивка: версионно-привязанные факты | ⬜ Not started | — |
| P0.3 | Карта поверхности атаки: маршруты × middleware × авторизация | ⬜ Not started | — |

## Phase P1

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P1.1 | AccessResolver: алгоритм H.2 и deny-вето | ⬜ Not started | — |
| P1.2 | Ролевые драйверы и AzGuard-шов: fail-closed | ⬜ Not started | — |
| P1.3 | Шэринг: share-token, публичная поверхность, download | ⬜ Not started | — |
| P1.4 | Загрузки: tus и S3 multipart | ⬜ Not started | — |
| P1.5 | CAS-блобы: дедуп, гонки, GC, storage_key | ⬜ Not started | — |
| P1.6 | Collab-шов: JWT и входящие webhook | ⬜ Not started | — |
| P1.7 | Исходящие webhook: outbox, подпись, SSRF | ⬜ Not started | — |
| P1.8 | Секреты, конфиг и HTTP-поверхность | ⬜ Not started | — |

## Phase P2

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P2.1 | Публичная поверхность и SemVer-контракт | ⬜ Not started | — |
| P2.2 | ServiceProvider: register-vs-boot, publishables, config-cache safety | ⬜ Not started | — |
| P2.3 | Миграции, префиксы таблиц, соединение, morph map | ⬜ Not started | — |
| P2.4 | Сквозная интеграция в хост: FlexCRM и чистый Laravel | ⬜ Not started | — |
| P2.5 | DX: README, quick-start, reference-доки, сообщения об ошибках, @vaulter/vue | ⬜ Not started | — |

## Phase P3

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P3.1 | Именование файлов: naming-стратегии как шов | ⬜ Not started | — |
| P3.2 | Панельная архитектура: Panel, PanelRegistry, PanelTablesBuilder, генератор миграций | ⬜ Not started | — |
| P3.3 | Типы документов как провайдеры: DocumentHandler, HandlerRegistry, экспортёры | ⬜ Not started | — |
| P3.4 | Id-стратегии и swappable-модели | ⬜ Not started | — |
| P3.5 | Драйверные швы: полнота, симметрия, обратная совместимость | ⬜ Not started | — |
| P3.6 | Опциональные подсистемы как надстройки (collab): гейт, публикация, install-путь | ⬜ Not started | — |

## Phase P4

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P4.1 | Индексы под фактические запросы | ⬜ Not started | — |
| P4.2 | N+1, рекурсивные обходы дерева, курсорная пагинация | ⬜ Not started | — |
| P4.3 | Тестовая стратегия: покрытие, arch-тесты, мутационное тестирование | ⬜ Not started | — |
| P4.4 | Статанализ, стиль, CI-матрица версий, supply-chain | ⬜ Not started | — |
| P4.5 | Фронтенд, e2e-стенд и Docker-поверхность | ⬜ Not started | — |

## Phase P5

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P5.1 | Сводный отчёт: ранжирование находок и топ-риски | ⬜ Not started | — |
| P5.2 | Ремедиационный бэклог и решение о follow-up плане | ⬜ Not started | — |
| P5.3 | Верификация pre-mortem-митигаций и передача материала в docs/ | ⬜ Not started | — |

## 6. Update Log

| Дата | Кто (role/model) | Что |
|:--|:--|:--|
| 1970-01-01 | migration/v2 | plan-design · in-progress · Layout v2 migration activation; source tree archived in artifacts/migration-source-v1. |

## Owner Gates

- —
