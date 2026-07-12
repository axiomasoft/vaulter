# RUNBOOK — единая цепочка закрытия: AUDIT-VLT → VLT-REMED → VLT-CANON (поглощает VLT-HSEAM)

**Дата:** 2026-07-12 · **Назначение:** один durable-файл со всей последовательностью до полного
закрытия и архивации всех 4 планов. Каждый шаг — либо готовая команда для НОВОЙ сессии Claude Code
(вставить и дождаться завершения), либо внешнее CI-действие (не Claude Code).

**Как пользоваться:** открой новую сессию → **сначала выставь Model/Thinking из строки шага**
(`/model <name>`, тумблер thinking) → вставь команду → дождись завершения → отметь `[x]` →
следующий шаг. Если сессия в конце выдаст `handoff.md` с ДРУГОЙ командой (эскалация/scope drift) —
доверяй `handoff.md` того плана, он источник правды на момент исполнения; этот файл — ожидаемый
путь по умолчанию, синхронизируй вручную при расхождении.

**Откуда модель/thinking:** команды `/task:plan-exec` (сонет/medium), `/task:plan-close`
(сонет/low), `/task:plan-design` (опус/high) САМИ пинят дефолт при запуске. Но `§Routing` каждого
плана переопределяет дефолт для конкретных риск-item'ов (SSOT-матрица `plan-protocol` §9) — этот
оверрайд команда не видит сама, его выставляет оператор ДО вставки команды. Ниже — уже подставленное
значение для каждого шага, вручную сверяться с `§Routing` планов больше не нужно.

**Дисциплина ассистента (внутри сессии, после каждой выполненной команды):** сразу после
завершения шага, БЕЗ ожидания вопроса — отметить `[x]`, зачитать `handoff.md`/статус-борд
результата и вывести следующий невыполненный шаг этого файла в launch-block-формате (таблица
Model/Thinking/Context/Суть + голая команда отдельным code-block). Молчать и ждать «что дальше?»
— ошибка (инцидент 2026-07-12: после `archive AUDIT-VLT` цепочка не продолжилась сама). Если
у команды по протоколу launch-block не обязателен (напр. `archive`) — всё равно вывести его,
это durable-цепочка, а не разовый вызов протокола.

**Решённое:** MSI-режим = **прогнать в реальном CI** (не advisory) — зафиксировано 2026-07-12.
**Уточнено 2026-07-12 (D31/D32 плана VLT-REMED):** прогон — **на ВЕТКЕ** (`git push` +
`gh workflow run mutation.yml --ref remediation/audit-vlt`), **без мержа в `main`**; после измерения
пороги `infection.json5` := факт − 5 п.п., а `|| true` со шага Infection снимается (гейт обязан уметь
падать). Работа оформлена item'ом **P5.4** — шаги 2.2/2.3 ниже свёрнуты в него.

---

## Часть 1 — AUDIT-VLT (закрыт, только архивация)

- [x] **1.1 — Архивировать** · `Model: sonnet · Thinking: low · Context: fresh session`
  (выполнено 2026-07-12, коммиты `740e77e` + `e9b2cc8`)
  ```
  /task:plan-close archive AUDIT-VLT
  ```

## Часть 2 — VLT-REMED (закрыт, кроме MSI)

- [ ] **2.1 — Формальный seal фазы P4** · `Model: sonnet · Thinking: low · Context: fresh session`
  (Status Board уже 10/10 🟢, это подтверждающий шаг)
  ```
  /task:plan-close VLT-REMED P4
  ```
- [x] **2.2/2.3 — СПРОЕКТИРОВАНО** (2026-07-12, `/task:plan-design VLT-REMED P5`): развилка по MSI
  разрешена владельцем (D31/D32), работа развёрнута в исполняемый item **P5.4** (`phases/P5.md`),
  RAG-база — `findings/P5.4-msi-ci.md`. Отдельного «не-Claude-Code» шага больше нет: push ветки +
  диспатч + чтение MSI из лога исполняет сам item.
- [x] **2.3′ — Цепочка P5.4→P5.10 ИСПОЛНЕНА** (2026-07-12): P5.4 (🔴 эскалация — `APP_KEY`) → P5.5
  (🟢 фикс окружения) → P5.6 (🔴 эскалация — путь JUnit) → P5.7 (🔴 эскалация — Infection↔Pest 4
  несовместим) → P5.8 (⛔ superseded) → P5.9 (🟢 лейн на нативную мутацию Pest, `Score: 100.00%`) →
  **P5.10 (🟢 порог `--min=95` по факту, критерий 5 закрыт, `tests.yml` для критерия 4 — коверидж
  `78.6 % < 80 %`, D35, честный долг)**. **Фаза P5 терминальна 10/10 🟢.** Итоговый вердикт
  release-readiness — «НЕ ГОТОВ К РЕЛИЗУ» по единственной причине (критерий 4, долг покрытия), не MSI.
  Детали — `plans/VLT-REMED/handoff.md`, `findings/P5.10-score-verdict.md`.
- [ ] **2.4 — Перевести план в терминал** · `Model: sonnet · Thinking: low · Context: fresh session`
  ```
  /task:plan-close VLT-REMED
  ```
- [ ] **2.5 — Архивировать** · `Model: sonnet · Thinking: low · Context: fresh session`
  ```
  /task:plan-close archive VLT-REMED
  ```

**Побочно, не на критическом пути:** `CHANGELOG.md`-наполнение — было заблокировано `deny`-правилом
харнесса; если актуально — отдельная сессия с доступом к файлу (sonnet/low), любой момент.

## Часть 3 — VLT-CANON (0/15 items) — поглощает и архивирует VLT-HSEAM

⚠ Перед стартом убедиться, что Часть 2 закрыта (VLT-CANON зависит от VLT-REMED — общие файлы:
контракты, `PanelTablesBuilder`). Каждая гейт-команда ниже уже несёт рекомендованное решение своей
развилки — запуск командой фиксированного текста в новой сессии = согласие с рекомендацией (если
хочешь другой вариант — сначала `/task:plan-design VLT-CANON <item>` с новым решением, opus/high).

### Фаза P0 — изоляция TipTap в драйвер (поглощает VLT-HSEAM)
- [ ] **3.1 (P0.1)** · `Model: opus · Thinking: high · Context: fresh session`
  (SemVer-публичный контракт + нейминг каскадит на фронт/пакеты — §Routing VLT-CANON)
  ```
  /task:plan-exec VLT-CANON P0.1 — перед стартом зафиксировать D7 (§8.1, рекоменд. вариант A: FileHandler + Contracts/Handlers/, kind-нейтраль) в Decision Log; approve owner получен запуском этой команды.
  ```
- [ ] **3.2 (P0.2)** · `Model: opus · Thinking: high · Context: fresh session`
  ```
  /task:plan-exec VLT-CANON P0.2
  ```
- [ ] **3.3 (P0.3)** · `Model: opus · Thinking: high · Context: fresh session`
  ```
  /task:plan-exec VLT-CANON P0.3
  ```
- [ ] **3.4 (P0.4)** · `Model: opus · Thinking: high · Context: fresh session`
  (решает D10 — перенос реестра в core, ревизия H.5+T5, миграция схемы)
  ```
  /task:plan-exec VLT-CANON P0.4 — перед стартом зафиксировать D10 (§8.4, рекоменд. вариант A: движок реестра → core, superseding H.5+T5 Option B) в Decision Log; approve owner получен запуском этой команды.
  ```
- [ ] **3.5 (P0.5)** · `Model: sonnet · Thinking: medium · Context: fresh session`
  (L4/L5 — механика дешёвая; Q2 уже пре-решён в тексте команды ниже, эскалация на opus/high только
  если при исполнении всплывут нюансы схемы вне ожидаемого)
  ```
  /task:plan-exec VLT-CANON P0.5 — перед стартом зафиксировать D11 (§8.5, рекоменд. оставить yjs_state как есть) в Decision Log; approve owner получен запуском этой команды.
  ```

### Фаза P1 — упаковка фронт-движков + фронт-реестр
- [ ] **3.6 (P1.1)** · `Model: opus · Thinking: high · Context: fresh session`
  (решает D8 — гейт для P1.2/P1.3)
  ```
  /task:plan-exec VLT-CANON P1.1 — перед стартом зафиксировать D8 (§8.2, рекоменд. единый @vaulter/vue, per-engine npm лениво) в Decision Log; approve owner получен запуском этой команды. Гейт для P1.2/P1.3.
  ```
- [ ] **3.7 (P1.2)** · `Model: sonnet · Thinking: medium · Context: fresh session`
  ```
  /task:plan-exec VLT-CANON P1.2
  ```
- [ ] **3.8 (P1.3)** · `Model: sonnet · Thinking: low · Context: fresh session`
  ```
  /task:plan-exec VLT-CANON P1.3
  ```

### Фаза P2 — финальный структурный канон
- [ ] **3.9 (P2.1)** · `Model: opus · Thinking: high · Context: fresh session`
  (azgard-rename — массовый namespace-refactor, closed-enum триггер эскалации `plan-protocol` §10)
  ```
  /task:plan-exec VLT-CANON P2.1
  ```
- [ ] **3.10 (P2.2)** · `Model: sonnet · Thinking: medium · Context: fresh session`
  (D9 пре-решён ниже — «оставить standalone»; если owner на месте передумает и выберет слияние —
  остановиться и эскалировать на opus/high, это уже другой объём работы)
  ```
  /task:plan-exec VLT-CANON P2.2 — перед стартом зафиксировать D9 (§8.3, рекоменд. оставить vaulter-media standalone) в Decision Log; approve owner получен запуском этой команды.
  ```
- [ ] **3.11 (P2.3)** · `Model: sonnet · Thinking: medium · Context: fresh session`
  ```
  /task:plan-exec VLT-CANON P2.3
  ```
- [ ] **3.12 (P2.4)** · `Model: sonnet · Thinking: low · Context: fresh session`
  ```
  /task:plan-exec VLT-CANON P2.4
  ```

### Фаза P3 — docs/ROADMAP/arch-тесты/supersede
- [ ] **3.13 (P3.1)** · `Model: sonnet · Thinking: low · Context: fresh session`
  (вкл. пункт ROADMAP «доделать vaulter-filament» — задел, не исполнение)
  ```
  /task:plan-exec VLT-CANON P3.1
  ```
- [ ] **3.14 (P3.2)** · `Model: sonnet · Thinking: medium · Context: fresh session`
  ```
  /task:plan-exec VLT-CANON P3.2
  ```
- [ ] **3.15 (P3.3)** · `Model: sonnet · Thinking: low · Context: fresh session`
  (формально помечает VLT-HSEAM superseded)
  ```
  /task:plan-exec VLT-CANON P3.3
  ```

### Закрытие VLT-CANON + архивация обоих планов
- [ ] **3.16** · `Model: sonnet · Thinking: low · Context: fresh session`
  ```
  /task:plan-close VLT-CANON
  ```
- [ ] **3.17** · `Model: sonnet · Thinking: low · Context: fresh session`
  ```
  /task:plan-close archive VLT-CANON
  ```
- [ ] **3.18** · `Model: sonnet · Thinking: low · Context: fresh session`
  (физический перенос в архив; P3.15 только пометил superseded)
  ```
  /task:plan-close archive VLT-HSEAM
  ```

---

## Гигиена `plans/ACTIVE.md` по ходу цепочки
- Сейчас (после шага 1.1): `Active: VLT-REMED`.
- После шага 2.5 (VLT-REMED заархивирован), перед стартом Части 3: `Active: VLT-CANON`.
- После шага 3.18: `Active: —` (ничего не активно) либо следующий план, если появится.

## Итог цепочки
24 шага (1 CI-шаг вне Claude Code, 23 команды, у каждой — своя Model/Thinking строкой над блоком).
Конец = 4/4 плана в архиве, `ACTIVE.md` чист, `vaulter-filament` — явный пункт в ROADMAP на будущее
(не потерян, но и не в scope).
