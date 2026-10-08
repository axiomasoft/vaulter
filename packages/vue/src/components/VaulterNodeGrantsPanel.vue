<script setup lang="ts">
import { onMounted, reactive } from 'vue'
import { useNodeGrants } from '../composables/useNodeGrants'
import type {
  GrantNodeAccessPayload,
  GrantRoleName,
  GrantScopeName,
  NodeGrant,
  NodeId,
  WorkspaceId,
} from '../types'

// Per-node ACL management over `useNodeGrants`: list grants (subject, role,
// scope, expiry), add a grant and revoke one. The host owns subject
// selection — it cannot know your users/roles/teams — so picking a subject is
// delegated to a `subject` slot (or a `resolveSubject` prop). Mirrors the legacy
// VaulterPermissionsPanel ergonomics: slot-driven rows, emit on grant/revoke.

/** A resolved ACL subject the panel grants/revokes against. */
export interface GrantSubject {
  type: string
  id: WorkspaceId
  /** An optional display label for the chosen subject. */
  label?: string
}

const props = withDefaults(
  defineProps<{
    nodeId: NodeId
    autoLoad?: boolean
    /** Roles offered in the add form. */
    roles?: GrantRoleName[]
    scopes?: GrantScopeName[]
    /**
     * A host-supplied subject resolver: given the in-form subject type + id, return
     * the subject to grant against (and optionally enrich the label). Defaults to
     * passing the raw type/id straight through.
     */
    resolveSubject?: (type: string, id: WorkspaceId) => GrantSubject | Promise<GrantSubject>
  }>(),
  {
    autoLoad: true,
    roles: () => ['previewer', 'reader', 'commenter', 'writer', 'organizer', 'owner'],
    scopes: () => ['item', 'subtree'],
    resolveSubject: undefined,
  },
)

const emit = defineEmits<{
  granted: [grant: NodeGrant]
  revoked: [subject: GrantSubject]
}>()

const { grants, loading, error, list, grant, revoke } = useNodeGrants(props.nodeId)

/** The add-grant form state; the `subject` slot writes type/id into here. */
const form = reactive<{
  subjectType: string
  subjectId: string
  role: GrantRoleName
  scope: GrantScopeName
  expiresAt: string
}>({
  subjectType: '',
  subjectId: '',
  role: props.roles[0] ?? 'reader',
  scope: props.scopes[0] ?? 'item',
  expiresAt: '',
})

/** Let the `subject` slot set the chosen subject directly (host resolver UI). */
function setSubject(subject: GrantSubject): void {
  form.subjectType = subject.type
  form.subjectId = String(subject.id)
}

async function add(): Promise<void> {
  if (!form.subjectType || !form.subjectId) {
    return
  }

  const resolved = props.resolveSubject
    ? await props.resolveSubject(form.subjectType, form.subjectId)
    : { type: form.subjectType, id: form.subjectId }

  const payload: GrantNodeAccessPayload = {
    subjectType: resolved.type,
    subjectId: resolved.id,
    role: form.role,
    scope: form.scope,
    expiresAt: form.expiresAt || undefined,
  }

  const created = await grant(payload)
  emit('granted', created)
  form.subjectType = ''
  form.subjectId = ''
  form.expiresAt = ''
}

async function remove(grantRow: NodeGrant): Promise<void> {
  const subject: GrantSubject = { type: grantRow.subject_type, id: grantRow.subject_id }
  await revoke({ subjectType: subject.type, subjectId: subject.id })
  emit('revoked', subject)
}

onMounted(() => {
  if (props.autoLoad) {
    void list()
  }
})

defineExpose({ list, grant: add, revoke: remove, grants, loading, error, setSubject })
</script>

<template>
  <div class="vaulter-node-grants-panel">
    <slot v-if="loading" name="loading">Loading…</slot>

    <ul v-else class="vaulter-node-grants-panel__list">
      <li v-for="g in grants" :key="g.id" class="vaulter-node-grants-panel__item" :data-role="g.role">
        <slot name="item" :grant="g" :revoke="() => remove(g)">
          <span class="vaulter-node-grants-panel__subject">{{ g.subject_type }}#{{ g.subject_id }}</span>
          <span class="vaulter-node-grants-panel__role">{{ g.role }}</span>
          <span class="vaulter-node-grants-panel__scope">{{ g.scope }}</span>
          <span v-if="g.expires_at" class="vaulter-node-grants-panel__expiry">until {{ g.expires_at }}</span>
          <button
            type="button"
            class="vaulter-node-grants-panel__revoke"
            :aria-label="`Revoke ${g.subject_type}#${g.subject_id}`"
            @click="remove(g)"
          >
            Revoke
          </button>
        </slot>
      </li>
    </ul>

    <form class="vaulter-node-grants-panel__add" @submit.prevent="add">
      <!-- The host supplies subject selection (users/roles/teams); `set-subject`
           writes the chosen subject into the form. The fallback is raw type/id. -->
      <slot name="subject" :set-subject="setSubject" :subject-type="form.subjectType" :subject-id="form.subjectId">
        <input
          v-model="form.subjectType"
          class="vaulter-node-grants-panel__subject-type"
          type="text"
          placeholder="Subject type (e.g. App\\User)"
          aria-label="Subject type"
        >
        <input
          v-model="form.subjectId"
          class="vaulter-node-grants-panel__subject-id"
          type="text"
          placeholder="Subject id"
          aria-label="Subject id"
        >
      </slot>

      <select v-model="form.role" class="vaulter-node-grants-panel__role-select" aria-label="Role">
        <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
      </select>
      <select v-model="form.scope" class="vaulter-node-grants-panel__scope-select" aria-label="Scope">
        <option v-for="scope in scopes" :key="scope" :value="scope">{{ scope }}</option>
      </select>
      <input
        v-model="form.expiresAt"
        class="vaulter-node-grants-panel__expiry-input"
        type="datetime-local"
        aria-label="Expires at"
      >

      <button type="submit" class="vaulter-node-grants-panel__grant">Grant</button>
    </form>

    <p v-if="error" class="vaulter-node-grants-panel__error" role="alert">
      <slot name="error" :error="error">Could not load grants.</slot>
    </p>
  </div>
</template>
