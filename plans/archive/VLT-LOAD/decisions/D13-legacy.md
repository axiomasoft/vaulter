---
id: D13
date: 1970-01-01
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Эталонный «железный конверт» — docker-стенд в `load/`, и «железо» задаётся `cpuset`-пиннингом, а не `--cpus`-квотой.** CFS-квота при исчерпании бюджета паркует процесс до конца периода → пилообразные всплески латентности, перцентили невоспроизводимы. Память — `mem_limit` == `memswap_limit` (swap off); `shared_buffers`/`work_mem` PG — от cgroup-лимита, не от RAM хоста.

## Почему

Imported noncanonical legacy decision row
