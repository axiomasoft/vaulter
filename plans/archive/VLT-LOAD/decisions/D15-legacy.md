---
id: D15
date: 1970-01-01
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Латентный класс B стартует как advisory, а не блокирующий** — в репо нет dedicated perf-раннера (`.github/workflows/`: 26× `runs-on: ubuntu-latest`, self-hosted нет), и SUT крутится на том же общем раннере, что и генератор. Блокирующим класс B становится при появлении perf-раннера ЛИБО при доказанном CV < 10 % по 5 прогонам (меряется в P2). Авторитетные латентные числа снимаются на контролируемом lab-box. Вопрос владельцу — `open-questions.md` Q1.

## Почему

Imported noncanonical legacy decision row
