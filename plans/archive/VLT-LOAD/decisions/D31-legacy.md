---
id: D31
date: 1970-01-01
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Seam'ы P8 — чисто АДДИТИВНАЯ инструментация, НЕ фиксы L-долгов ⇒ D11 их не блокирует.** `$metrics->…()` не добавляет инвертируемого управляющего потока (нет `===`/ветки/элемента массива для мутанта), каждый seam покрыт Fake-ассертом. Поэтому инструментация файлов с непогашенным VLT-MUT-долгом (`S3MultipartHandler` 38, `SweepUploadsCommand` 17, `MoveNodeAction` 4) легальна: `vaulter_request_peak_memory_bytes`/`vaulter_assemble_*` меряют L-01 **до** фикса (P4 меряет после — прибор обязан стоять раньше фикса). D11 остаётся барьером только для самого dev-среза-фикса.

## Почему

Imported noncanonical legacy decision row
