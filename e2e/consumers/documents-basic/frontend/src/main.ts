import { createApp } from 'vue'
import { createVaulterClient, VaulterPlugin } from '@vaulter/vue'
import DocumentList from './DocumentList.vue'
import TextDocument from './TextDocument.vue'
import './style.css'

const driveId = new URL(location.href).searchParams.get('drive')
if (driveId === null || driveId === '') {
  throw new Error('The documents-basic page needs a drive id in ?drive=')
}

const client = createVaulterClient()
createApp(DocumentList, { driveId })
  .use(VaulterPlugin, {
    client,
    viewers: {
      'vaulter/tiptap': TextDocument,
    },
  })
  .mount('#app')
