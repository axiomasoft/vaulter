<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useShares } from '../composables/useShares'
import { useVaulterClient } from '../composables/useVaulterClient'
import type {
  CreateShareInput,
  GrantScope,
  MintedShare,
  NodeId,
  Share,
  ShareAudience,
  ShareRole,
} from '../api/types'

// Bearer share-link management over `useShares`. On create, the raw token + share URL are
// surfaced EXACTLY ONCE (via `lastCreated`) with a copy button — the listed shares never
// carry the secret. Slot-driven rows, emit on create/rotate/revoke. A revoked link is gone
// from the list (the server deletes it), so there is no "revoked" state to render.

const props = withDefaults(
  defineProps<{
    nodeId: NodeId
    autoLoad?: boolean
    roles?: ShareRole[]
    audiences?: ShareAudience[]
    scopes?: GrantScope[]
  }>(),
  {
    autoLoad: true,
    roles: () => ['previewer', 'reader', 'commenter', 'writer'],
    audiences: () => ['anyone', 'authenticated'],
    scopes: () => ['item', 'subtree'],
  },
)

const emit = defineEmits<{
  created: [share: MintedShare]
  rotated: [share: MintedShare]
  revoked: [shareId: string]
}>()

const client = useVaulterClient()
const { shares, lastCreated, loading, error, list, create, rotate, revoke, clearLastCreated } = useShares(props.nodeId)

const form = reactive<{ role: ShareRole, audience: ShareAudience, scope: GrantScope, expiresAt: string }>({
  role: props.roles[0] ?? 'reader',
  audience: props.audiences[0] ?? 'anyone',
  scope: props.scopes[0] ?? 'item',
  expiresAt: '',
})

const copied = ref(false)

async function add(): Promise<void> {
  // The server decides which role fits which audience (a writer link needs a signed-in audience).
  const input: CreateShareInput = {
    role: form.role,
    audience: form.audience,
    scope: form.scope,
    expiresAt: form.expiresAt || undefined,
  }
  const created = await create(input)
  copied.value = false
  emit('created', created)
}

async function replaceToken(share: Share): Promise<void> {
  const rotated = await rotate(share.id)
  copied.value = false
  emit('rotated', rotated)
}

async function remove(share: Share): Promise<void> {
  await revoke(share.id)
  emit('revoked', share.id)
}

/**
 * The share URL to display: the create response carries an absolute `url`; if it's missing,
 * fall back to the client's public download URL built from the raw token.
 */
function shareUrl(created: MintedShare): string {
  return created.url || client.publicShares.downloadUrl(created.token)
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
    // A failure is captured in `error` and shown through the error slot.
    void list().catch(() => undefined)
  }
})

defineExpose({ list, create: add, rotate: replaceToken, revoke: remove, shares, lastCreated, loading, error, clearLastCreated })
</script>

<template>
  <div class="vaulter-share-panel">
    <!-- The one-time token + URL: shown ONCE, right after create. -->
    <div v-if="lastCreated" class="vaulter-share-panel__created" role="status">
      <slot
        name="created"
        :share="lastCreated"
        :url="shareUrl(lastCreated)"
        :copy="() => copy(shareUrl(lastCreated!))"
        :dismiss="clearLastCreated"
        :copied="copied"
      >
        <p class="vaulter-share-panel__created-note">
          Copy this link now — the token is shown only once.
        </p>
        <input
          class="vaulter-share-panel__token"
          type="text"
          :value="shareUrl(lastCreated)"
          readonly
          aria-label="Share link"
          @focus="($event.target as HTMLInputElement).select()"
        >
        <button type="button" class="vaulter-share-panel__copy" @click="copy(shareUrl(lastCreated))">
          {{ copied ? 'Copied' : 'Copy' }}
        </button>
        <button type="button" class="vaulter-share-panel__dismiss" @click="clearLastCreated">
          Dismiss
        </button>
      </slot>
    </div>

    <slot v-if="loading" name="loading">Loading…</slot>

    <ul v-else class="vaulter-share-panel__list">
      <li v-for="share in shares" :key="share.id" class="vaulter-share-panel__item">
        <slot name="item" :share="share" :rotate="() => replaceToken(share)" :revoke="() => remove(share)">
          <span class="vaulter-share-panel__role">{{ share.role }}</span>
          <span class="vaulter-share-panel__audience">{{ share.audience }}</span>
          <span class="vaulter-share-panel__scope">{{ share.scope }}</span>
          <span v-if="share.has_password" class="vaulter-share-panel__protected">password</span>
          <span class="vaulter-share-panel__uses">{{ share.use_count }}{{ share.max_uses !== null ? ` / ${share.max_uses}` : '' }} uses</span>
          <span v-if="share.expires_at" class="vaulter-share-panel__expiry">until {{ share.expires_at }}</span>
          <button
            type="button"
            class="vaulter-share-panel__rotate"
            :aria-label="`Rotate token of share ${share.id}`"
            @click="replaceToken(share)"
          >
            Rotate
          </button>
          <button
            type="button"
            class="vaulter-share-panel__revoke"
            :aria-label="`Revoke share ${share.id}`"
            @click="remove(share)"
          >
            Revoke
          </button>
        </slot>
      </li>
    </ul>

    <form class="vaulter-share-panel__create" @submit.prevent="add">
      <select v-model="form.role" class="vaulter-share-panel__role-select" aria-label="Role">
        <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
      </select>
      <select v-model="form.audience" class="vaulter-share-panel__audience-select" aria-label="Audience">
        <option v-for="audience in audiences" :key="audience" :value="audience">{{ audience }}</option>
      </select>
      <select v-model="form.scope" class="vaulter-share-panel__scope-select" aria-label="Scope">
        <option v-for="scope in scopes" :key="scope" :value="scope">{{ scope }}</option>
      </select>
      <input
        v-model="form.expiresAt"
        class="vaulter-share-panel__expiry-input"
        type="datetime-local"
        aria-label="Expires at"
      >
      <button type="submit" class="vaulter-share-panel__submit">Create link</button>
    </form>

    <p v-if="error" class="vaulter-share-panel__error" role="alert">
      <slot name="error" :error="error">Could not load shares.</slot>
    </p>
  </div>
</template>
