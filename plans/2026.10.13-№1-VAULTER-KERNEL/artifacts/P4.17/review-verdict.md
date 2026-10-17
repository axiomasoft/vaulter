# RC4 (P4.13–P4.17), codex gpt-6.1-sol xhigh, по diff через stdin

Находки (все plausible), принято 3 из 3:
1. P1 ModuleUninstallCommand: TOCTOU между проверкой строк/pins и удалением → fix: перепроверка внутри транзакции и перед каждым down(), fail closed, WARN «выключите модуль и остановите трафик»; внешний lock не вводится (окно сужено, не закрыто; pgsql READ COMMITTED).
2. P2 ModuleUninstallCommand: purge коммитился до rollBack, ошибка down() давала exit 1 «ничего не изменено» → fix: транзакционный DDL (sqlite/pgsql, то же соединение) — purge+down атомарно; MySQL/direct-соединение — exit 3 PARTIAL «применено частично, повторите --force».
3. P2 WebhooksSchema::keyColumns() пуст → fix: HK tenant_id, REF drive_id/endpoint_id + WebhooksKeyColumnsTest.
Filament (I9): нарушений в показанном diff не найдено.
Не покрыто: diff без перенесённых webhooks-файлов (Jobs/Models/Repositories/Testing) и без тестов.
