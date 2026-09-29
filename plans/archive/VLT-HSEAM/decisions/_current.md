<!-- plan-projection
projection: decision-current
projection_version: v1
source_scope: decisions/
through: 2026-07-10/D8
inputs_sha256: 582d20876801831a325fdd71b4e3d554426b46a42f3128c344400446fb873b5a
generated_by: task plan-views decisions
-->

# Текущие решения

> Не править руками: это генерируемый вид.

| ID | Date | Status | Items | Решение |
|:--|:--|:--|:--|:--|
| D1 | 2026-07-10 | accepted | — | Реестр обработчиков НЕ строить заново — он существует и корректен; скоуп = закрытие утечек |
| D2 | 2026-07-10 | accepted | — | Единица стороннего расширения = composer-пакет, биндящийся в core-контракты; НЕ каталог в ядре |
| D3 | 2026-07-10 | accepted | — | Kind-нейтральный нейминг до 1.0: `Contracts/Documents/*`→`Contracts/Handlers/*`, `DocumentHandler`→`FileHandler`, `DocumentCapabilities`→`HandlerCapabilities` |
| D4 | 2026-07-10 | accepted | — | Публичный контракт остаётся узким bespoke-интерфейсом; `Illuminate\Support\Manager` НЕ вводить |
| D5 | 2026-07-10 | accepted | — | `DocumentExporter::export()` принимает опаковый `DocumentContent`; TipTap-специфика — в реализациях documents |
| D6 | 2026-07-10 | accepted | — | Политика эволюции capabilities: открытое множество, аддитивные оси с дефолтами, клиенты игнорируют незнакомые ключи; fallback-хендлер = инвариант «новый тип ≠ breaking» |
| D7 | 2026-07-10 | accepted | — | Динамический резолв mime→handler сохранить; handler-id на Node НЕ персистить |
| D8 | 2026-07-10 | accepted | — | Хендлеры под будущие типы НЕ пре-имплементировать; готовность = открытый контракт + fallback + DX-каркас (Abstract-база + skeleton-гайд) |
