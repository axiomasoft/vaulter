<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'

// A tiny accessible NAME-input dialog: a single text field for "new folder" and
// "rename" (and any other name-entry flow). Mirrors VaulterFolderPicker's modal
// shape — backdrop + panel, role="dialog" aria, an `open` prop, `@close`, Esc to
// close, focus-on-open — but instead of a folder tree it holds one <input>.
//
// On open the field is seeded from `initialValue`, focused and fully selected (so
// a rename pre-selects the current name for instant overtyping). Enter submits,
// Esc / backdrop / cancel dismiss. `submit` carries the TRIMMED value and only
// fires when non-empty; the confirm button is disabled while the trim is empty.
//
// Headless by default to match the rest of the Drive: minimal scoped styles,
// class hooks + header/footer slots, no new dependency.
const props = withDefaults(
  defineProps<{
    /** Whether the dialog is shown. */
    open?: boolean
    /** Heading text (e.g. 'New folder' / 'Rename'). */
    title?: string
    /** The input's accessible label. */
    label?: string
    /** Prefill — empty for new-folder, the node name for rename. */
    initialValue?: string
    /** The confirm button's text. */
    confirmLabel?: string
    /** The input placeholder. */
    placeholder?: string
  }>(),
  {
    open: false,
    title: 'Name',
    label: 'Name',
    initialValue: '',
    confirmLabel: 'Save',
    placeholder: '',
  },
)

const emit = defineEmits<{
  /** Confirmed with a non-empty, TRIMMED name. */
  'submit': [name: string]
  /** Dismissed (Esc / overlay / cancel) — no submit happens. */
  'close': []
}>()

/** The live field value, seeded from `initialValue` whenever the dialog opens. */
const value = ref<string>(props.initialValue)

/** The confirm gate: the trimmed value must be non-empty. */
function trimmed(): string {
  return value.value.trim()
}

function submit(): void {
  const name = trimmed()
  if (name === '') {
    return
  }
  emit('submit', name)
}

function close(): void {
  emit('close')
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.preventDefault()
    close()
  }
}

/** The text field — focused + fully selected on open for instant overtyping. */
const input = ref<HTMLInputElement | null>(null)

// Open (or a changed seed while open) → (re)seed from `initialValue`, then focus and
// select-all. Re-opening always resets the field, so a stale value from a previous open
// can never leak through — and watching `initialValue` too keeps the seed honest even if
// a host reuses ONE instance across targets (new→rename) without a close between.
watch(
  () => [props.open, props.initialValue] as const,
  ([isOpen]) => {
    if (isOpen) {
      value.value = props.initialValue
      void nextTick(() => {
        input.value?.focus()
        input.value?.select()
      })
    }
  },
  { immediate: true },
)

defineExpose({ value, submit })
</script>

<template>
  <div
    v-if="open"
    class="vaulter-name-dialog"
    role="dialog"
    aria-modal="true"
    :aria-label="title"
    @keydown="onKeydown"
  >
    <div class="vaulter-name-dialog__backdrop" @click="close" />

    <div class="vaulter-name-dialog__panel" role="document">
      <header class="vaulter-name-dialog__header">
        <slot name="header" :title="title">
          <span class="vaulter-name-dialog__title">{{ title }}</span>
        </slot>
        <button
          type="button"
          class="vaulter-name-dialog__close"
          aria-label="Close"
          @click="close"
        >
          ×
        </button>
      </header>

      <input
        ref="input"
        v-model="value"
        class="vaulter-name-dialog__input"
        type="text"
        :aria-label="label"
        :placeholder="placeholder"
        @keydown.enter.prevent="submit"
      >

      <footer class="vaulter-name-dialog__footer">
        <slot
          name="footer"
          :submit="submit"
          :cancel="close"
          :value="value"
          :can-submit="trimmed() !== ''"
        >
          <button
            type="button"
            class="vaulter-name-dialog__submit"
            :disabled="trimmed() === ''"
            @click="submit"
          >
            {{ confirmLabel }}
          </button>
          <button type="button" class="vaulter-name-dialog__cancel" @click="close">
            Cancel
          </button>
        </slot>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.vaulter-name-dialog {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}
.vaulter-name-dialog__backdrop {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
}
.vaulter-name-dialog__panel {
  position: relative;
  max-width: 24rem;
  width: 100%;
  background: #fff;
  border-radius: 0.5rem;
  padding: 1rem;
}
.vaulter-name-dialog__input {
  width: 100%;
  box-sizing: border-box;
}
.vaulter-name-dialog__submit[disabled] {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
