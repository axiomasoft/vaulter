# Частное дополнение: справочник

## Коллаборация {#collaboration}

Редактирование в реальном времени — отдельный opt-in пакет `axioma-studio/vaulter-collaboration` с файлом конфигурации
`config/vaulter-collaboration.php` (тег публикации `vaulter-collaboration-config`). Он активен, только когда
`vaulter.modules.collaboration` равен `true`; тогда обязательны URL хаба (`hub.url`) и оба секрета (`hub.jwt_secret`,
`hub.webhook_secret`, заданные и разные) — без них приложение не загружается. Ключи (`hub.*`, `control.*`, `mode`,
`limits.*`, `compaction.*`, `drain.*`, `retention.*`, `schedule.*`) перечислены в
[Совместном редактировании → Конфигурация](collaboration.md#конфигурация).


