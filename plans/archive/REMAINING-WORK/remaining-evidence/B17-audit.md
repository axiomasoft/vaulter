# B17 — итоговый offline-аудит handoff

**Дата:** 2026-09-29. **Вердикт: REPAIRS REQUIRED — 2 Major, confidence high.**

Source: `9c8497a03f0d8f7275796805ee2dea65e67040e0`. Scope: final P7 repairs B14-01…06,
R20 facts/readback, source/evidence fingerprints и retained historical/deferred dispositions.
Два новых controls вызывают настоящий `run_window()` с подменёнными внешними peers,
затем передают его output настоящему full verifier в complete 51-cell / 77-attempt fixture.
Оба нарушения принимаются producer и reader. Это воспроизведение ошибок допуска evidence,
не утверждение о воспроизведённой потере/порче данных реальным продуктом.

## Findings

### B17-01 — Major / issue / high: потеря multipart rows вместе с objects признаётся legitimate absence

**Место:** [p7_object_inventory.py:91](../../../../load/gates/p7_object_inventory.py#L91),
особенно `present = part is not None and not terminal` на строке 96;
`received_bytes` проверяется только на тип/неотрицательность на строках 61–62.

После подготовки `s3-complete` в upload session записано `status=uploading`,
`received_bytes=16`. Если после fault нет ни part row, ни соответствующего object,
а listing пуст, inventory добавляет attempted `.parts/1`, но объявляет его отсутствие
обязательным: `required=false`. Staged и final тоже должны отсутствовать. Все шесть
направленных joins получают `matched-absent`, producer выдаёт `evidence=valid`,
и **полный verifier принимает эту попытку**.

Это отличается от допустимого отказа до первой записи: authoritative received count
уже положителен. В [S3MultipartHandler.php:167](../../../../packages/drive/src/Uploads/S3MultipartHandler.php#L167)
он устанавливается из `recordedSize()`. Отсутствующий объём нельзя подтвердить
пустым набором part rows и объектов. Сейчас потеря обеих сторон join скрывает
нарушение durable integrity, хотя удаление только object существующего part
успешно обнаруживается прежними controls.

**Основание:** контракт R11/B14-05 — required presence/absence выводится из DB/payload
state, missing required objects отвергаются; [load/README.md:655](../../../../load/README.md#L655).
**Маршрут:** `R11-B17-01`, load-only integrity contract и ordinary offline regressions.
Связать положительный multipart received count с полным набором durable parts/bytes
для открытой session; учитывать assembled/terminal states отдельно. Нельзя исправлять
это общим запретом `matched-absent`: сохранить pre-write zero-byte отказ и корректные
commit/abort/expiry. Контроль должен отвергаться и producer, и full reader.

### B17-02 — Major / issue / high: final checksum не связан с payload и CAS key

**Место:** [p7_object_inventory.py:73](../../../../load/gates/p7_object_inventory.py#L73)
и строка 81, где expected checksum берётся из `blob`, а не проверяется против
`attempt['payload_checksum']` и content-addressed key.

Контроль: session committed; payload checksum `b` × 64; storage key
`global/bb/bb/<b×64>`; blob checksum и измеренный checksum final object оба `c` × 64.
До fault final отсутствовал; размеры совпадают; staging/parts после commit отсутствуют.
Несмотря на противоречие content address, producer выдаёт `valid`, final joins —
`matched`, и **полный verifier принимает попытку**. Сравнение blob key с predicted key
проверяет путь, но не связывает его с checksum записанных bytes. Совпадение DB/object
между собой здесь недостаточно для доказательства сохранности исходного payload.

**Основание:** тот же DB/payload integrity contract, включая durable keys и checksums
([load/README.md:571](../../../../load/README.md#L571)); CAS H.3 — адрес содержимого строится
из server-recomputed SHA-256, см. комментарий
[CommitUploadAction.php:22](../../../../packages/drive/src/Actions/Uploads/CommitUploadAction.php#L22).
**Маршрут:** `R11-B17-02`, load-only final-CAS invariant и offline controls.
Проверить согласованность payload checksum, blob checksum, final key и observed
checksum; текущую проверку размера сохранить. Producer и full reader должны отвергать
control, а корректный committed fixture — проходить.

## Проверенное покрытие

- Все **168 P7 offline tests passed**, exit **0** (20.191 s). Включают реальный
  Compose renderer без daemon, PHP command с controlled peers, ACK/holder ordering,
  expected absence, inventory collectors и concurrent no-replace publisher.
- Повтор B14-02 readback: **5/5 negative controls REJECTED**; complete positive fixture
  admitted byte-stably. B14-03 readback отвергает unfaulted HTTP 200 ACK; старый порядок
  воспроизводится только на saved source. B14-05 readback отвергает leftover part,
  extra part, leftover stage, missing second object и collector error. Все три
  readback commands завершились exit **0**.
- **85 source/log fingerprint entries** B14-02…06 совпали. Source hashes каждого
  repair проверены на его commit (`85c0afd`, `dd23b68`, `2c1e70f`, `cf967c7`, `9c8497a`),
  а latest hash каждого из **23 файлов** — на текущих bytes. Recorded source rollups
  B14-02…05 совпали. Later authorized source changes не объявлялись drift старого proof.
- R21: **9 source + 2 test hashes** совпали; R24: **8 source + 19 log hashes** совпали.
  PHP/package suites в B17 не перезапускались; сохранённые результаты не выдаются за новый run.
- Historical P7: **176 файлов**, SHA-256
  `b51a143828512efb5bb85c5d4f05863677545ae183f2ee3b9f407e3100895a62`.
  Full reader по-прежнему отвергает run: `recovered without dependency probe`.
  Это ожидаемый rejection, не product success.
- R20 `readback.py`: exit **0**, JSON полностью совпал с сохранённым `readback.json`
  на входном dirty baseline. Подтверждены 12 deferred rows, repair commits, floor 80,
  отсутствие `Schedule::` в packages и hashes cited inputs.
- R25 `readback.py`: exit **0**; все observations совпали после исключения только
  временных argv paths. Raw/archive digests неизменны. RED cells/attempts:
  P6 **36/108**, R01 **19/55**, R09-first **9/25**, R09-follow-up **8/24**, R10 **9/26**.
  Actual verifier exits **0/1/1/1/1**, исторический exit 0 P6 не означает product GREEN.
- Read-only GitHub API повторно подтвердил private repo, main protection **404**,
  rulesets `[]`, отсутствие `tests.yml` run для current SHA и тег/release `v0.2.0`.
  Run conclusions/SHA **29203669361 failure**, **29414127913 success**,
  **29409528976 split failure**, **29414127853 mutation success** совпали с R20.
  Coverage 78.6% проверена по сохранённому evidence, новый coverage не измерялся.
  Split-repo/Packagist absence не запрашивались повторно: retained R20 observations.

Исходные B14-01…06 controls проходят в своих записанных scopes. Новые B17-01/02
уточняют пределы integrity admission; они не отменяют исправления route restore,
fault ACK/holder barrier и atomic publication и не требуют повторного полного аудита.
После repairs нужен focused recheck новых findings и соседних valid/invalid cases.

## Retained dispositions и передача исполнителю

Все **8 live gaps** (16c, 17a, 20, 21, 26, 30, 31, 34) и **4 cold-mutation gaps**
(14, 22, 23, 25) остаются ОТЛОЖЕНО. Drive **86.20%**, gate 95 exit 1; R18 **95.33%**
и R19 **95.51%** относятся только к их frozen sources. Live stand, chaos/SLO,
PHP/PG suites и mutation в B17 не запускались. Historical SLO-61/SLO-70/GC scan+batch
RED, R03 rejected, historical P7 rejected, M/L/24-hour и current-source gaps сохранены.
R24 code/test closure не заменяет R26 recovery/duplicate proof; identities двух
первоначальных failed jobs остаются неизвестны.

Owner decisions R20 сохраняются: current coverage gap, required-check enforcement,
release/split/Packagist provisioning/publication, documented-but-unproved host scheduler,
accepted ADR-0018 limitation, optional docs debt, каждый retained product residual.
Ни B17, ни passing offline tests не принимают этот риск; product GREEN/B08 admission,
publish/deploy и close/archive не выданы.

**Рекомендация следующего действия:** `$ task:dev plans/REMAINING-WORK.md B17-sync`.
Перенести этот результат и два named corrective routes в лист; не завершать offline
handoff и не переходить к шагу 38 до focused repair/recheck либо явного owner disposition.
Предлагаемые code/test строки — `R11-B17-01` и `R11-B17-02`, только `load/` integrity
и tests. Причины и scopes указаны выше; отсутствие live не является новым finding.
При регистрации выполненного B17 и двух новых repairs расчёт: active **3 → 4**
(`−1 audit +2 repairs`), done **35 → 36**, deferred **12 → 12**, conditional **1 → 1**.
Это предлагаемый update для sync; сам аудит таблицу/указатель не меняет и repairs не запускает.

Единственный созданный repository artifact — этот отчёт, по explicit audit exception
в `REMAINING-WORK.md` («the auditor writes only its Bxx-audit.md report»).
Pre-existing изменения листа, B14-audit и R20-checks сохранены. Технические временные
outputs находятся в `/tmp`; воспроизводимый control полностью приведён ниже.

## Fingerprints

| Artifact | SHA-256 |
|:--|:--|
| `load/gates/p7_object_inventory.py` | `ea794b89affeac31f566b0c6032875302f22bfe8c70d6247abcacba09c3be234` |
| `load/gates/p7_live_matrix.py` | `4939b13a5bf707069ad69595fde68d65c912bc6bc052dc75f9940113c530579a` |
| `load/gates/p7_chaos.py` | `51224236580a2dffab5074fd83d130ec5bebfc7ec29e88e02ab7c6403a4c6106` |
| Input `plans/REMAINING-WORK.md` | `ba5e3a1660778d4a2e1fa0d5896ed11f6927ef8fec1bb4f614090caf1c643e46` |
| Input `plans/remaining-evidence/B14-audit.md` | `2fe8ac9ac9af2f69f7d19e0cec1741c42df79e7a7fefb1949ff773ec27617184` |
| Control script below | `63e108e6e8149a9c9872e46c5005665c65151abe45c7f5baaa5731eb50038189` |

## Reproducible controls

Сохранить блок как `/tmp/vaulter-b17-controls.py`, выполнить из root:
`PYTHONDONTWRITEBYTECODE=1 python3 -B /tmp/vaulter-b17-controls.py`.
Actual exit **0** означает успешное воспроизведение **двух false admissions**:
`producer=valid`, `full_reader=accepted` в обоих случаях; не успешный integrity gate.
Все live boundaries заменены controlled peers; fixture files создаются только
в `TemporaryDirectory`. Product/package bytes и historical evidence не меняются.

```python
import copy, json, sys
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path.cwd()/'load/gates'))
import p7_live_matrix as live
import p7_chaos as gate
import test_p7_evidence as ev
from test_p7_integrity_contract import IntegrityContractTest

def probe(kind):
    cell=next(c for c in gate.catalog() if c['cell_id']=='product-realistic.s3-complete.503')
    fixture={'run_id':ev.RUN,'marker':live.product_ops.marker_for(ev.RUN,cell['cell_id'],1),
             'upload_id':'b17-upload','payload_sha256':ev.CHECKSUM,'requested_bytes':16,'cas_scope':'global'}
    upload={'entity':'upload_session','entity_id':'session-1','upload_id':'b17-upload',
            'status':'uploading','received_bytes':16,'assembled_blob_id':None}
    entities=[upload]
    if kind=='wrong-final-content':
        upload.update(status='committed',assembled_blob_id='blob-1')
        entities.append({'entity':'blob','entity_id':'blob-1','status':'committed','storage_key':ev.FINAL_KEY,'checksum':'c'*64,'size':16})
    calls=[]
    def sample(key, expected_checksum=None):
        calls.append(key)
        exists=kind=='wrong-final-content' and key==ev.FINAL_KEY and len(calls)>1
        return {'key':key,'exists':exists,'checksum':'c'*64 if exists else None,'size':16 if exists else 0,
                'observation':'observed' if exists else 'absent'}
    streams={name:[] for name in gate.JSONL_FILES}
    with ExitStack() as stack:
        stack.enter_context(patch.object(live,'_run',side_effect=AssertionError('no live boundary')))
        for name,value in {'host_status':200,'host_probe':(200,'vaulter_metric 1'),'arm':'s3-fault-proxy',
                           'barrier_ack':ev.ack_for(cell),'revert':None,'cleanup_fixture':None,
                           'sample_entities':entities,'object_listing':{'status':'observed','keys':[]}}.items():
            stack.enter_context(patch.object(live,name,return_value=value))
        stack.enter_context(patch.object(live,'object_sample',side_effect=sample))
        stack.enter_context(patch.object(live.product_ops.ProductOperationRunner,'prepare',return_value=fixture))
        stack.enter_context(patch.object(live.product_ops.ProductOperationRunner,'execute',return_value={
            'operation':'s3-complete','status':201 if kind=='wrong-final-content' else 503,
            'transport':'http','request_id':'b17-control','requested_bytes':0,'written_bytes':None}))
        record=live.run_window(streams,live.Clock(),cell,1,ev.RUN,gate.config_fingerprint(),'a'*64)
    reader='accepted'
    try: IntegrityContractTest('runTest').admit(record,streams)
    except gate.EvidenceInvalid as e: reader='rejected: '+str(e)
    return {'control':kind,'producer':record['evidence'],'full_reader':reader,'received_bytes':upload['received_bytes'],
            'payload_checksum':ev.CHECKSUM,'joins':[{k:r[k] for k in ('object_key','status')} for r in streams['reconcile.jsonl']]}
print(json.dumps([probe(k) for k in ('lost-parts-with-positive-received','wrong-final-content')],indent=2))
```
