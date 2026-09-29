---
id: D9
date: 2026-07-10
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

Research-этап разбит надвое: широкая RAG-верификация сделана **на этапе проектирования** (выжимки `findings/P0-{dms-security,upload-hardening,collab-seam,embeddable-laravel}.md`), а версионно-привязанные факты добиваются в P0.2 через context7.

## Почему

Дешёвая модель не умеет формулировать поисковые запросы под нормы безопасности и галлюцинирует источники; при этом версии библиотек надо проверять на момент исполнения, а не проектирования. RAG:✅ 2026-07-10 (perplexity-web ×4, context7 `/websites/tus_io`).
