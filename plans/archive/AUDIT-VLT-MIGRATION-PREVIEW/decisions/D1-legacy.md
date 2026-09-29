---
id: D1
date: 2026-07-10
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

Границы аудита: PHP-монорепо (`packages/*`), Vue-пакет (`packages/vue`), Docker-поверхность (`e2e/docker`, `hocuspocus-server/`), конфиг и миграции. Вне границ: `vendor/`, `node_modules/`, содержимое хост-проектов.

## Почему

Аудит должен покрывать поставляемую поверхность пакета, а не транзитивные зависимости; supply-chain отдельной осью в P4.4. Alternatives: (а) только core — отвергнуто, дыры в collab-сервере и Docker остались бы невидимыми (pre-mortem R5.1); (б) включить FlexCRM целиком — отвергнуто, это чужой репозиторий, читаем его только в P2.4 как потребителя.
