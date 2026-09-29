---
id: D8
date: 1970-01-01
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Unbounded-scan'ы — первоклассные цели P5/P6.** `SweepUploadsCommand` `->get()`, GC `->cursor()` без batch-cap, `WebhookRetryDispatcher::claimDue()` без limit, `OutboxRelay::subscribedEndpoints` O(endpoints)-в-PHP — измерить деградацию на «грязных» объёмах, завести долг на батчинг/чекпоинты.

## Почему

Imported noncanonical legacy decision row
