<script setup lang="ts">
import { onMounted, reactive } from 'vue'
import { useNodeGrants } from '../composables/useNodeGrants'
import type {
  GrantEffectName,
  GrantLevelName,
  GrantNodeAccessPayload,
  GrantScopeName,
  NodeGrant,
  NodeId,
  WorkspaceId,
} from '../types'

// Per-node ACL management over `useNodeGrants`: list grants (subject, level,
// effect, scope, expiry), add a grant and revoke one. The host owns subject
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
    /** Levels offered in the add form. */
    levels?: GrantLevelName[]
    effects?: GrantEffectName[]
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
    levels: () => ['reader', 'commenter', 'editor', 'manager'],
    effects: () => ['allow', 'deny'],
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
  level: GrantLevelName
  effect: GrantEffectName
  scope: GrantScopeName
  expiresAt: string
}>({
  subjectType: '',
  subjectId: '',
  level: props.levels[0] ?? 'reader',
  effect: props.effects[0] ?? 'allow',
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
    level: form.level,
    effect: form.effect,
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
      <li v-for="g in grants" :key="g.id" class="vaulter-node-grants-panel__item" :data-effect="g.effect">
        <slot name="item" :grant="g" :revoke="() => remove(g)">
          <span class="vaulter-node-grants-panel__subject">{{ g.subject_type }}#{{ g.subject_id }}</span>
          <span class="vaulter-node-grants-panel__level">{{ g.level }}</span>
          <span class="vaulter-node-grants-panel__effect">{{ g.effect }}</span>
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

      <select v-model="form.level" class="vaulter-node-grants-panel__level-select" aria-label="Level">
        <option v-for="level in levels" :key="level" :value="level">{{ level }}</option>
      </select>
      <select v-model="form.effect" class="vaulter-node-grants-panel__effect-select" aria-label="Effect">
        <option v-for="effect in effects" :key="effect" :value="effect">{{ effect }}</option>
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
