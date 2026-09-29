# P4 RAG — PHP-FPM memory и streaming evidence

Дата проверки: 2026-09-20. Метод: Perplexity `search_advanced` (`web+academic`) для candidate
sources/measurement pitfalls, затем обычный web-поиск и прямая сверка каждого нормативного факта с
первичным источником. Perplexity synthesis сам по себе не считается authority.

## Verified facts

1. **PHP peak.** `memory_get_peak_usage(true)` возвращает максимальный объём, выделенный PHP memory
   manager из системы; `false` — только `emalloc()`. Это не process RSS/PSS.
   RAG:✅ 2026-09-20 https://www.php.net/manual/en/function.memory-get-peak-usage.php
2. **FPM recycle.** `pm.max_requests` задаёт число запросов child до respawn; `0` — бесконечно. Это
   lifecycle control, но не memory sampler и не гарантия, что внешний poll успеет увидеть пик.
   RAG:✅ 2026-09-20 https://www.php.net/manual/en/install.fpm.configuration.php
3. **Process evidence.** `/proc/<pid>/smaps_rollup` суммирует mapping statistics; PSS делит shared
   pages пропорционально, а `Pss_Anon`/`Pss_File` разделяют anonymous и file-backed footprint.
   `VmHWM` в `/proc/<pid>/status` — lifetime peak resident set. Чтение чужого process может требовать
   ptrace/perfmon capability, поэтому sampler разделяет PID namespace и остаётся read-only.
   RAG:✅ 2026-09-20 https://docs.kernel.org/filesystems/proc.html
4. **Cgroup evidence.** cgroup v2 `memory.current` — total cgroup+descendants; accounting включает
   userland anonymous/page cache, kernel structures и TCP buffers. `memory.peak` — maximum since
   creation либо FD-local reset; `memory.stat` нужен для breakdown. Поэтому fresh cgroup — надёжнее
   попытки использовать reset из другого процесса.
   RAG:✅ 2026-09-20 https://docs.kernel.org/admin-guide/cgroup-v2.html
5. **k6 download client.** `discardResponseBodies:true` меняет default `responseType` на `none` и
   уменьшает память/GC load generator; отдельный request также может задать `responseType:'none'`.
   RAG:✅ 2026-09-20 https://grafana.com/docs/k6/latest/using-k6/k6-options/reference/#discard-response-bodies
6. **AWS multipart boundary.** AWS S3 `CompleteMultipartUpload` получает список `PartNumber`/`ETag`
   и S3 собирает ранее загруженные parts по возрастанию номера. Payload parts не пересылаются в
   complete request. Это не эквивалент текущего proxied Vaulter handler, который читает свои part
   objects и исторически конкатенировал их в PHP.
   RAG:✅ 2026-09-20 https://docs.aws.amazon.com/AmazonS3/latest/API/API_CompleteMultipartUpload.html

## Repository facts and design consequences

- RAG:— `observability-spec.md` §5.2: `vaulter_request_peak_memory_bytes` пишет
  `memory_get_peak_usage(true)` как run-max; P4 называет его PHP allocator peak, не RSS.
- RAG:— `S3MultipartHandler::complete()` всё ещё конкатенирует `$assembled`; D7/D29 уже доказали
  L-01 арифметикой и 500 MiB OOM. P4 требует отдельный post-fix carrier и не повторяет pre-fix proof.
- RAG:— `TusUploadHandler` получает resource, `Sha256ContentStore::stage()` читает по 1 MiB,
  `DownloadController` читает по 256 KiB; это code hypotheses, а не измеренные memory verdicts.
- RAG:— P4 принимает fixed ceilings из SLO-40…54 до live run и публикует allocator/process/cgroup
  ряды рядом. Page-cache-only рост не классифицируется как PHP heap regression.

## Rejected shortcuts

- Только `memory_get_peak_usage(true)` — недостаточно для RSS/PSS claim.
- Только `docker stats`/`memory.current` — недостаточно для heap claim из-за page cache/socket/process mix.
- tmpfs для multi-GiB fixture — загрязняет memory accounting.
- `drop_caches` — host-wide destructive mutation и не нужен для memory-envelope verdict.
- Считать AWS-native complete и Vaulter proxied complete одним путём — неверная граница системы.
