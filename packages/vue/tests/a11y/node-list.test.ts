import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/vue'
import VaulterNodeList from '../../src/components/VaulterNodeList.vue'
import type { Node } from '../../src/api/types'
import { makeFile, makeNode } from '../support/nodes'

// Testing Library only auto-cleans when the test globals are enabled; this package keeps them off.
afterEach(cleanup)

const listOf = (...names: string[]): Node[] => names.map((name, index) => makeFile({ id: String(index + 1), name }))

const rows = () => screen.getAllByRole('option')
const focused = () => document.activeElement as HTMLElement

describe('VaulterNodeList — listbox semantics', () => {
  it('is a labelled listbox of options, with exactly one tab stop', () => {
    render(VaulterNodeList, { props: { nodes: listOf('a.txt', 'b.txt', 'c.txt') } })

    const listbox = screen.getByRole('listbox', { name: 'Files' })
    expect(within(listbox).getAllByRole('option')).toHaveLength(3)
    expect(rows().map((row) => row.getAttribute('tabindex'))).toEqual(['0', '-1', '-1'])
  })

  it('accepts a custom accessible name', () => {
    render(VaulterNodeList, { props: { nodes: listOf('a.txt'), label: 'Shared files' } })

    expect(screen.getByRole('listbox', { name: 'Shared files' })).toBeTruthy()
  })

  it('reflects the selection in aria-selected', () => {
    render(VaulterNodeList, { props: { nodes: listOf('a.txt', 'b.txt'), selectedId: '2' } })

    expect(rows().map((row) => row.getAttribute('aria-selected'))).toEqual(['false', 'true'])
  })

  it('moves the single tab stop to the selected row', () => {
    render(VaulterNodeList, { props: { nodes: listOf('a.txt', 'b.txt'), selectedId: '2' } })

    expect(rows().map((row) => row.getAttribute('tabindex'))).toEqual(['-1', '0'])
  })
})

describe('VaulterNodeList — keyboard', () => {
  it('ArrowDown / ArrowUp move focus between rows and stop at the ends', async () => {
    render(VaulterNodeList, { props: { nodes: listOf('a.txt', 'b.txt', 'c.txt') } })
    rows()[0].focus()

    await fireEvent.keyDown(focused(), { key: 'ArrowDown' })
    await nextTick()
    expect(focused()).toBe(rows()[1])

    await fireEvent.keyDown(focused(), { key: 'ArrowDown' })
    await nextTick()
    expect(focused()).toBe(rows()[2])

    await fireEvent.keyDown(focused(), { key: 'ArrowDown' })
    await nextTick()
    expect(focused()).toBe(rows()[2])

    await fireEvent.keyDown(focused(), { key: 'ArrowUp' })
    await nextTick()
    expect(focused()).toBe(rows()[1])
  })

  it('Home and End jump to the first and last row, and the tab stop follows the focus', async () => {
    render(VaulterNodeList, { props: { nodes: listOf('a.txt', 'b.txt', 'c.txt') } })
    rows()[1].focus()

    await fireEvent.keyDown(focused(), { key: 'End' })
    await nextTick()
    expect(focused()).toBe(rows()[2])
    expect(rows().map((row) => row.getAttribute('tabindex'))).toEqual(['-1', '-1', '0'])

    await fireEvent.keyDown(focused(), { key: 'Home' })
    await nextTick()
    expect(focused()).toBe(rows()[0])
  })

  it('Enter opens the focused node, Space selects it', async () => {
    const { emitted } = render(VaulterNodeList, { props: { nodes: listOf('a.txt', 'b.txt') } })
    rows()[1].focus()

    await fireEvent.keyDown(focused(), { key: 'Enter' })
    await fireEvent.keyDown(focused(), { key: ' ' })

    expect(emitted().open[0]).toEqual([expect.objectContaining({ id: '2' })])
    expect(emitted().select[0]).toEqual([expect.objectContaining({ id: '2' })])
  })

  it('F2 asks to rename and Delete asks to trash the focused node', async () => {
    const { emitted } = render(VaulterNodeList, {
      props: { nodes: listOf('a.txt'), actions: ['rename', 'trash'] },
    })
    rows()[0].focus()

    await fireEvent.keyDown(focused(), { key: 'F2' })
    await fireEvent.keyDown(focused(), { key: 'Delete' })

    expect(emitted().action).toEqual([
      ['rename', expect.objectContaining({ id: '1' })],
      ['trash', expect.objectContaining({ id: '1' })],
    ])
  })

  it('F2 and Delete do nothing when the caller lacks the ability or the action is not offered', async () => {
    const readOnly = makeNode({ id: '1', kind: 'file', abilities: { edit: false, trash: false } })
    const { emitted, rerender } = render(VaulterNodeList, { props: { nodes: [readOnly], actions: ['rename', 'trash'] } })
    rows()[0].focus()

    await fireEvent.keyDown(focused(), { key: 'F2' })
    await fireEvent.keyDown(focused(), { key: 'Delete' })
    expect(emitted().action).toBeUndefined()

    // Able to, but the host did not offer trash: still no event.
    await rerender({ nodes: listOf('a.txt'), actions: ['rename'] })
    rows()[0].focus()
    await fireEvent.keyDown(focused(), { key: 'Delete' })
    expect(emitted().action).toBeUndefined()
  })

  it('ignores keys pressed on a button inside a row', async () => {
    const { emitted } = render(VaulterNodeList, { props: { nodes: listOf('a.txt', 'b.txt'), actions: ['download'] } })
    const button = screen.getAllByRole('button', { name: 'download a.txt' })[0]

    await fireEvent.keyDown(button, { key: 'ArrowDown' })
    await fireEvent.keyDown(button, { key: 'Delete' })

    expect(emitted().action).toBeUndefined()
  })

  it('keeps every row action reachable by keyboard (buttons stay in the tab order)', () => {
    render(VaulterNodeList, { props: { nodes: listOf('a.txt'), actions: ['download', 'share'] } })

    for (const button of screen.getAllByRole('button')) {
      expect(button.getAttribute('tabindex')).toBeNull()
    }
  })
})

describe('VaulterNodeList — focus after a mutation', () => {
  /** A host that owns the list, like VaulterDrive does, and can drop a node. */
  function host(initial: Node[]) {
    const nodes = ref(initial)
    const Host = defineComponent({
      setup() {
        return () => h(VaulterNodeList, { nodes: nodes.value, actions: ['trash'] })
      },
    })

    return { Host, drop: (id: string) => { nodes.value = nodes.value.filter((node) => node.id !== id) } }
  }

  it('moves focus to the next row when the focused row is removed', async () => {
    const { Host, drop } = host(listOf('a.txt', 'b.txt', 'c.txt'))
    render(Host)
    rows()[1].focus()

    drop('2')
    await nextTick()
    await nextTick()

    expect(rows().map((row) => row.textContent)).toEqual([expect.stringContaining('a.txt'), expect.stringContaining('c.txt')])
    expect(focused()).toBe(rows()[1])
    expect(focused().textContent).toContain('c.txt')
  })

  it('moves focus to the previous row when the last row is removed', async () => {
    const { Host, drop } = host(listOf('a.txt', 'b.txt', 'c.txt'))
    render(Host)
    rows()[2].focus()

    drop('3')
    await nextTick()
    await nextTick()

    expect(focused()).toBe(rows()[1])
    expect(focused().textContent).toContain('b.txt')
  })

  it('does not steal focus from elsewhere on the page when a row is removed', async () => {
    const { Host, drop } = host(listOf('a.txt', 'b.txt'))
    const outside = document.createElement('input')
    document.body.appendChild(outside)
    render(Host)
    outside.focus()

    drop('2')
    await nextTick()
    await nextTick()

    expect(focused()).toBe(outside)
    outside.remove()
  })

  it('keeps one tab stop on the neighbour after the removal', async () => {
    const { Host, drop } = host(listOf('a.txt', 'b.txt', 'c.txt'))
    render(Host)
    rows()[0].focus()

    drop('1')
    await nextTick()
    await nextTick()

    expect(rows().map((row) => row.getAttribute('tabindex'))).toEqual(['0', '-1'])
  })
})
