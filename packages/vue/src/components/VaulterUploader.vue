<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useUpload } from '../composables/useUpload'
import { useVaulterClient } from '../composables/useVaulterClient'
import { humanByteSize } from './drive/nodeDisplay'
import type { DriveId, Node, NodeId } from '../api/types'

// A reusable, standalone upload zone: drag-drop + file picker, driving the
// `useUpload` composable. Small files go direct; files past `resumableThreshold`
// use the resumable (tus) path, which reports byte progress and can be cancelled.
// Headless-ish: minimal scoped styles, class hooks + slots so hosts can restyle.

const props = withDefaults(
  defineProps<{
    driveId: DriveId
    /** The folder to upload into (null/undefined = the drive root). */
    parentId?: NodeId | null
    /** Bytes above which the resumable (tus) path is used. Default 5 MiB. */
    resumableThreshold?: number
    /** The `accept` attribute for the file input. */
    accept?: string
    /** Allow selecting more than one file. */
    multiple?: boolean
    disabled?: boolean
  }>(),
  {
    parentId: null,
    resumableThreshold: 5 * 1024 * 1024,
    accept: undefined,
    multiple: true,
    disabled: false,
  },
)

const emit = defineEmits<{
  /** A file finished uploading — its created node. */
  uploaded: [node: Node]
  /** A file upload failed. */
  error: [file: File, error: unknown]
}>()

/** One tracked upload in the list, with its own progress + abort. */
interface UploadItem {
  id: number
  name: string
  size: number
  sent: number
  status: 'uploading' | 'done' | 'error' | 'cancelled'
  abort: (() => Promise<void>) | null
}

/** The last failure, announced to assistive tech through the live region. */
const announcement = ref('')

function failureMessage(name: string, error: unknown): string {
  const reason = error instanceof Error && error.message !== '' ? error.message : 'upload failed'

  return `Upload of ${name} failed: ${reason}`
}

// Resolve the client ONCE in setup; per-file uploaders are spun up after a user
// event (outside the inject() context), so they receive this resolved client.
const client = useVaulterClient()

const items = reactive<UploadItem[]>([])
const isDragging = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
let nextId = 0

function openPicker(): void {
  fileInput.value?.click()
}

function onPick(event: Event): void {
  const input = event.target as HTMLInputElement
  if (input.files) {
    void enqueue(Array.from(input.files))
  }
  // Reset so re-picking the same file fires `change` again.
  input.value = ''
}

function onDrop(event: DragEvent): void {
  isDragging.value = false
  if (props.disabled) {
    return
  }
  const files = event.dataTransfer?.files
  if (files && files.length > 0) {
    void enqueue(Array.from(files))
  }
}

function onDragOver(): void {
  if (!props.disabled) {
    isDragging.value = true
  }
}

function onDragLeave(): void {
  isDragging.value = false
}

async function enqueue(files: File[]): Promise<void> {
  await Promise.all(files.map((file) => uploadOne(file)))
}

/** Each file gets its OWN `useUpload` so progress is tracked independently. */
async function uploadOne(file: File): Promise<void> {
  items.push({
    id: nextId++,
    name: file.name,
    size: file.size,
    sent: 0,
    status: 'uploading',
    abort: null,
  })
  // Mutate the REACTIVE proxy element, not the raw object we pushed — otherwise
  // the template never re-renders on a progress/status change.
  const item = items[items.length - 1]

  const uploader = useUpload(props.driveId, client)

  try {
    let node: Node
    if (file.size > props.resumableThreshold) {
      item.abort = () => uploader.abort()
      node = await uploader.uploadResumable(file, {
        parentId: props.parentId,
        onProgress: (sent) => {
          item.sent = sent
        },
      })
    } else {
      node = await uploader.upload(file, { parentId: props.parentId })
      item.sent = item.size
    }
    item.status = 'done'
    item.abort = null
    announcement.value = ''
    emit('uploaded', node)
  } catch (error) {
    // An aborted upload is a cancellation, not an error.
    item.status = item.status === 'cancelled' ? 'cancelled' : 'error'
    item.abort = null
    if (item.status !== 'cancelled') {
      announcement.value = failureMessage(file.name, error)
      emit('error', file, error)
    }
  }
}

async function cancel(item: UploadItem): Promise<void> {
  if (item.abort) {
    item.status = 'cancelled'
    await item.abort()
  }
}

function clearFinished(): void {
  for (let i = items.length - 1; i >= 0; i -= 1) {
    if (items[i].status !== 'uploading') {
      items.splice(i, 1)
    }
  }
}

function progressOf(item: UploadItem): number {
  return item.size > 0 ? Math.min(item.sent / item.size, 1) : item.status === 'done' ? 1 : 0
}

defineExpose({ openPicker, items, clearFinished })
</script>

<template>
  <div
    class="vaulter-uploader"
    :class="{ 'is-dragging': isDragging, 'is-disabled': disabled }"
    @dragover.prevent="onDragOver"
    @dragleave.prevent="onDragLeave"
    @drop.prevent="onDrop"
  >
    <input
      ref="fileInput"
      type="file"
      class="vaulter-uploader__input"
      :accept="accept"
      :multiple="multiple"
      :disabled="disabled"
      hidden
      @change="onPick"
    >

    <button
      type="button"
      class="vaulter-uploader__dropzone"
      :disabled="disabled"
      @click="openPicker"
    >
      <slot name="dropzone" :open-picker="openPicker" :is-dragging="isDragging">
        Drop files here or click to browse
      </slot>
    </button>

    <!-- Always in the DOM so the announcement of a failure is read the moment it is set. -->
    <p class="vaulter-uploader__announce" role="alert" aria-live="assertive" aria-atomic="true">{{ announcement }}</p>

    <ul v-if="items.length > 0" class="vaulter-uploader__list">
      <li
        v-for="item in items"
        :key="item.id"
        class="vaulter-uploader__item"
        :data-status="item.status"
      >
        <slot
          name="item"
          :item="item"
          :progress="progressOf(item)"
          :cancel="() => cancel(item)"
        >
          <span class="vaulter-uploader__name">{{ item.name }}</span>
          <span class="vaulter-uploader__meta">
            {{ humanByteSize(item.sent) }} / {{ humanByteSize(item.size) }}
          </span>
          <progress
            class="vaulter-uploader__progress"
            :value="progressOf(item)"
            max="1"
            :aria-label="`Uploading ${item.name}`"
          />
          <button
            v-if="item.status === 'uploading' && item.abort"
            type="button"
            class="vaulter-uploader__cancel"
            :aria-label="`Cancel ${item.name}`"
            @click="cancel(item)"
          >
            Cancel
          </button>
          <span v-else class="vaulter-uploader__state">{{ item.status }}</span>
        </slot>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.vaulter-uploader.is-dragging {
  outline: 2px dashed currentColor;
}
.vaulter-uploader__dropzone {
  display: block;
  width: 100%;
}
</style>
