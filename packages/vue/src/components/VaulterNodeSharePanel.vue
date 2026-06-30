<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useNodeShares } from '../composables/useNodeShares'
import { useVaulterClient } from '../composables/useVaulterClient'
import type {
  CreateNodeSharePayload,
  GrantLevelName,
  GrantScopeName,
  NodeId,
  NodeShare,
  NodeShareCreated,
} from '../types'

// Bearer share-link management over `useNodeShares`. On create, the raw token +
// share URL are surfaced EXACTLY ONCE (via `lastCreated`) with a copy button — the
// listed shares only ever carry a `token_prefix`, never the secret. Mirrors the
// legacy VaulterSharePanel ergonomics: slot-driven rows, emit on create/revoke.

const props = withDefaults(
  defineProps<{
    nodeId: NodeId
    autoLoad?: boolean
    levels?: GrantLevelName[]
    scopes?: GrantScopeName[]
  }>(),
  {
    autoLoad: true,
    levels: () => ['reader', 'commenter', 'editor', 'manager'],
    scopes: () => ['item', 'subtree'],
  },
)

const emit = defineEmits<{
  created: [share: NodeShareCreated]
  revoked: [shareId: NodeId]
}>()

const client = useVaulterClient()
const { shares, lastCreated, loading, error, list, create, revoke, clearLastCreated } = useNodeShares(props.nodeId)

const form = reactive<{ level: GrantLevelName, scope: GrantScopeName, requireLogin: boolean, expiresAt: string }>({
  level: props.levels[0] ?? 'reader',
  scope: props.scopes[0] ?? 'item',
  requireLogin: false,
  expiresAt: '',
})

const copied = ref(false)

async function add(): Promise<void> {
  const payload: CreateNodeSharePayload = {
    level: form.level,
    scope: form.scope,
    requireLogin: form.requireLogin,
    expiresAt: form.expiresAt || undefined,
  }
  const created = await create(payload)
  copied.value = false
  emit('created', created)
}

async function remove(share: NodeShare): Promise<void> {
  await revoke(share.id)
  emit('revoked', share.id)
}

/**
 * The share URL to display: the create response carries an absolute `url`; if it's
 * missing, fall back to the client's public download URL built from the raw token.
 */
function shareUrl(created: NodeShareCreated): string {
  return created.url || client.nodeShares.publicDownloadUrl(created.token)
}

async function copy(text: string): Promise<void> {
  try {
    await navigator.clipboard?.writeText(text)
    copied.value = true
  } catch {
    // Clipboard may be unavailable (no permission / insecure context) — the host
    // can still copy from the rendered, selectable token field.
    copied.value = false
  }
}

onMounted(() => {
  if (props.autoLoad) {
    void list()
  }
})

defineExpose({ list, create: add, revoke: remove, shares, lastCreated, loading, error, clearLastCreated })
</script>

<template>
  <div class="vaulter-node-share-panel">
    <!-- The one-time token + URL: shown ONCE, right after create. -->
    <div v-if="lastCreated" class="vaulter-node-share-panel__created" role="status">
      <slot
        name="created"
        :share="lastCreated"
        :url="shareUrl(lastCreated)"
        :copy="() => copy(shareUrl(lastCreated!))"
        :dismiss="clearLastCreated"
        :copied="copied"
      >
        <p class="vaulter-node-share-panel__created-note">
          Copy this link now — the token is shown only once.
        </p>
        <input
          class="vaulter-node-share-panel__token"
          type="text"
          :value="shareUrl(lastCreated)"
          readonly
          aria-label="Share link"
          @focus="($event.target as HTMLInputElement).select()"
        >
        <button type="button" class="vaulter-node-share-panel__copy" @click="copy(shareUrl(lastCreated))">
          {{ copied ? 'Copied' : 'Copy' }}
        </button>
        <button type="button" class="vaulter-node-share-panel__dismiss" @click="clearLastCreated">
          Dismiss
        </button>
      </slot>
    </div>

    <slot v-if="loading" name="loading">Loading…</slot>

    <ul v-else class="vaulter-node-share-panel__list">
      <li v-for="share in shares" :key="share.id" class="vaulter-node-share-panel__item" :data-status="share.revoked_at ? 'revoked' : 'active'">
        <slot name="item" :share="share" :revoke="() => remove(share)">
          <span class="vaulter-node-share-panel__prefix">{{ share.token_prefix }}…</span>
          <span class="vaulter-node-share-panel__level">{{ share.level }}</span>
          <span class="vaulter-node-share-panel__state">{{ share.revoked_at ? 'revoked' : 'active' }}</span>
          <span v-if="share.expires_at" class="vaulter-node-share-panel__expiry">until {{ share.expires_at }}</span>
          <button
            v-if="!share.revoked_at"
            type="button"
            class="vaulter-node-share-panel__revoke"
            :aria-label="`Revoke share ${share.token_prefix}`"
            @click="remove(share)"
          >
            Revoke
          </button>
        </slot>
      </li>
    </ul>

    <form class="vaulter-node-share-panel__create" @submit.prevent="add">
      <select v-model="form.level" class="vaulter-node-share-panel__level-select" aria-label="Level">
        <option v-for="level in levels" :key="level" :value="level">{{ level }}</option>
      </select>
      <select v-model="form.scope" class="vaulter-node-share-panel__scope-select" aria-label="Scope">
        <option v-for="scope in scopes" :key="scope" :value="scope">{{ scope }}</option>
      </select>
      <label class="vaulter-node-share-panel__require-login">
        <input v-model="form.requireLogin" type="checkbox"> Require login
      </label>
      <input
        v-model="form.expiresAt"
        class="vaulter-node-share-panel__expiry-input"
        type="datetime-local"
        aria-label="Expires at"
      >
      <button type="submit" class="vaulter-node-share-panel__submit">Create link</button>
    </form>

    <p v-if="error" class="vaulter-node-share-panel__error" role="alert">
      <slot name="error" :error="error">Could not load shares.</slot>
    </p>
  </div>
</template>
