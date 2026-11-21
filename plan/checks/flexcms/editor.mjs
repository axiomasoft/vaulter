import { createApp, h, onBeforeUnmount, onMounted, ref } from 'vue'
import { createVaulterClient, VaulterDocumentNode, VaulterPlugin } from '@vaulter/vue'
import { Editor } from '@tiptap/core'
import { StarterKit } from '@tiptap/starter-kit'

const client = createVaulterClient()
const node = await client.nodes.get(new URL(location.href).searchParams.get('id'))

const EditorSlot = {
  props: ['content', 'readonly', 'setContent'],
  setup(props) {
    const element = ref()
    let editor
    onMounted(() => {
      editor = new Editor({
        element: element.value,
        content: props.content,
        extensions: [StarterKit],
        editable: !props.readonly,
        onUpdate: ({ editor }) => props.setContent(editor.getJSON()),
      })
    })
    onBeforeUnmount(() => editor?.destroy())
    return () => h('div', { ref: element })
  },
}

const app = createApp({
  render() {
    return h(VaulterDocumentNode, { node, debounceMs: 160 }, {
      default: (slot) => slot.content
        ? h(EditorSlot, {
            content: slot.content,
            readonly: slot.readonly,
            setContent: slot.setContent ?? slot['set-content'],
          })
        : null,
    })
  },
})
app.use(VaulterPlugin, { client }).mount('#app')
