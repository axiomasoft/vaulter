// BOUNDARY: in Vaulter the schema comes from the Tiptap extensions of packages/vue
// (StarterKit + Link + the blockId extension of task 3.1, via @tiptap/core getSchema()).
// This port keeps the same node/mark names and the attributes the algorithm depends on:
// paragraph/heading carry attrs.blockId, link carries href and is non-inclusive, hardBreak is
// an inline leaf of size 1. Any schema passed to replace-exact.mjs must satisfy the same port.
import { Schema } from 'prosemirror-model';

export const schema = new Schema({
  nodes: {
    doc: { content: 'block+' },
    paragraph: { group: 'block', content: 'inline*', attrs: { blockId: { default: null } } },
    heading: {
      group: 'block',
      content: 'inline*',
      attrs: { level: { default: 1 }, blockId: { default: null } },
    },
    text: { group: 'inline' },
    hardBreak: { group: 'inline', inline: true, selectable: false },
  },
  marks: {
    link: {
      attrs: { href: {}, target: { default: null }, rel: { default: null }, class: { default: null } },
      inclusive: false,
    },
    bold: {},
    italic: {},
  },
});

/** Block types addressable by text.replace_exact@1 in v1 (vaulter.md 3.1). */
export const ADDRESSABLE = new Set(['paragraph', 'heading']);
