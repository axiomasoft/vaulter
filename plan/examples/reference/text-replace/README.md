# text.replace_exact@1 на уровне ProseMirror

- `replace-exact.mjs` содержит `planReplaceExact` (prepare: внутренние предусловия), `applyReplaceExact` (commit на свежем состоянии через `prosemirror-transform`), `verifyTextAndMarks` (`at_commit`/`current`) и `readSegments` (проекция `text_block`). Используются версии из корневого `package-lock.json` Vaulter: prosemirror-model 1.25.12, prosemirror-transform 1.12.2, orderedmap 2.1.1.
- `schema.mjs`: BOUNDARY-порт схемы Tiptap (`paragraph`/`heading` с `blockId`, `link` с `href`, `hardBreak`).
- `replace_exact.php` — зеркало для PHP-адаптера на сохранённом Tiptap JSON.
- `vectors.json` содержит общие случаи: два похожих фрагмента, символ вне BMP, ссылку, перекрывающиеся совпадения, ненормализованные run, `hardBreak`, а также commit после правки соседнего блока, цели и `href`.
- `exchange.json` — wire-обмен: отказ на неоднозначную цель, prepare/commit, verify at_commit → passed, current → failed после ручной правки, повторная at_commit → passed. В `proposal_record` показаны внутренние предусловия, закреплённые по `proposal_id`.

Правила описаны в `plan/spec/agent-sdk.md`, раздел «Команды содержимого». Запуск: `bash plan/checks/sdk/run.sh`.
