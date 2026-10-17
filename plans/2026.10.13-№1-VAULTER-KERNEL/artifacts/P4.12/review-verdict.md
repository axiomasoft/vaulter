# RC3 verdict (codex gpt-6.1-sol high, diff через stdin, 552c6e11..49d6c59b)

- **P2 · plausible — `packages/drive/src/Vaulter.php:69`** — У объекта, доступного через прежний контейнерный алиас `'vaulter'`, исчез `withoutTenancy()`. Существующий вызов `app('vaulter')->withoutTenancy($callback)` теперь завершится `Call to undefined method`. Метод, добавленный непосредственно в фасад, обслуживает только статические вызовы. Для сохранения этого публичного API нужен делегат и у нового объекта `Vaulter`.

- **P2 · plausible — `packages/core/src/Configuration/VaulterConfig.php:85`** — `featureEnabled()` теперь безусловно создаёт класс из опционального drive-пакета. В приложении с core без установленного drive вызов `VaulterConfig::featureEnabled('activity')` вместо прежнего `false` завершится `Class "Vaulter\Drive\Configuration\DriveConfig" not found`. Нужна обработка отсутствующего модуля либо независимый от drive способ чтения флагов.

Ревью только по diff; обе находки без воспроизведения.
## Проверка
1 REJECTED: удаление withoutTenancy у экземпляра — решение плана P4.12 (Tenancy::without в core + статический метод фасада), пакет не публиковался (D21). 2 PARTIAL/nit: featureEnabled() без drive падает, но вызывающих в core нет, VaulterConfig-делегат убирается в P4.16 → nits. Fix-коммит не требуется.
