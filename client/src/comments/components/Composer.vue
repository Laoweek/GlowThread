<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue'
import { AtSign, Send } from '@lucide/vue'
import type { Comment } from '@/shared/types'
import { Avatar } from '@/ui/avatar'
import { Button } from '@/ui/button'
import { Textarea } from '@/ui/textarea'
import { Spinner } from '@/ui/spinner'
import { toast } from '@/ui/toast'
import EmojiPicker from './EmojiPicker.vue'
import MentionList from './MentionList.vue'
import { useStore } from '../context'
import { mentionQuery, rankCandidates, userPrefix } from '../mentions'
import { deserialize, serialize, shiftUnits, tokenIds, validUnits } from '../units'
import type { Unit } from '../units'
import { actionGet } from '@/lib/api'
import { errorMessage } from '@/lib/errors'
import { t, num } from '@/lib/i18n'
import { codePointLength } from '@/lib/text'
import { displayName } from '@/lib/names'
import { afterPress } from '@/lib/press'
import { DRAFT_TTL, draftKey as keyFor } from '../drafts'

/**
 * The composer. On the page it is one line until focused, then three lines with the
 * emoji and @ buttons, a character count near the limit and Send (Ctrl or ⌘ + Enter).
 * Under a comment (replyTo) it opens ready to type, addressed to that person.
 * A name picked from the @ suggestions is a mention unit (units.ts): highlighted, removed whole
 * by Backspace at its end, plain text again once edited inside, sent as a token.
 */
const props = defineProps<{ replyTo?: Comment | null, portal: HTMLElement }>()
const emit = defineEmits<{ done: [], cancel: [] }>()
const store = useStore()

const user = mw.config.get('wgUserName') as string | null
// Drafts are per wiki and account and expire after a week (a shared device must not show
// someone else's unsent text for long).
const draftKey = keyFor(String(mw.config.get('wgWikiID') || ''), user, props.replyTo ? 'reply:' + props.replyTo.id : 'page:' + store.config.pageId)
const uid = 'gt-c' + Math.random().toString(36).slice(2, 8)

const restored = readDraft()
const text = ref(restored.text)
// The picked mentions in `text`, and the text they were last placed in (`last`).
const units = shallowRef<Unit[]>([])
let last = ''
// Every text with its units, for Undo and Redo: the browser decides which text comes back, and
// it gets the units it had at that point. `step` is the current one.
const steps: Array<{ text: string, units: Unit[] }> = []
let step = -1
setUnits(restored.units)
// The text to send: mentions as tokens. The length limit counts it.
const source = computed(() => serialize(text.value, units.value))
// Said to screen readers when a mention is added, comes back or turns into plain text.
const note = ref('')
const focused = ref(false)
const posting = ref(false)
const error = ref('')
const status = ref('')
const box = ref<{ el: HTMLTextAreaElement | null, resize(): void } | null>(null)
const field = ref<HTMLElement | null>(null)
const frame = ref<HTMLElement | null>(null)

const reply = computed(() => !!props.replyTo)
const expanded = computed(() => reply.value || focused.value || text.value.length > 0 || posting.value)
const max = computed(() => (store.data.value ? store.data.value.config.maxLength : 1000))
const length = computed(() => codePointLength(source.value))
const remaining = computed(() => max.value - length.value)
const showCount = computed(() => remaining.value <= Math.min(100, Math.round(max.value * 0.1)))
const waitSeconds = computed(() => Math.max(0, Math.ceil((store.state.rateLimitedUntil - clock.value) / 1000)))
const canSend = computed(() => length.value > 0 && remaining.value >= 0 && !posting.value && waitSeconds.value === 0)
const replyName = computed(() => (props.replyTo && props.replyTo.author.name && !props.replyTo.author.hidden ? displayName(props.replyTo.author.name, store.canModerate.value) : ''))
const placeholder = computed(() => (reply.value ? (replyName.value ? t('reply-placeholder', replyName.value) : t('reply-placeholder-plain')) : t('composer-placeholder')))

// ---- drafts: kept per page in this browser only, a convenience that may be missing. A draft
// is the text to send (mentions as tokens) with the names it showed.

function readDraft(): { text: string, units: Unit[] } {
  try {
    const d = JSON.parse(window.localStorage.getItem(draftKey) || 'null')
    if (d && typeof d.text === 'string' && Date.now() - d.at < DRAFT_TTL) return deserialize(d.text, d.names || {})
  } catch {
    // storage blocked or a broken entry
  }
  return { text: '', units: [] }
}
function saveDraft(keep = true) {
  try {
    const names: Record<number, string> = {}
    for (const u of units.value) names[u.id] = u.name
    if (keep && text.value) window.localStorage.setItem(draftKey, JSON.stringify({ text: source.value, names, at: Date.now() }))
    else window.localStorage.removeItem(draftKey)
  } catch {
    // storage blocked: drafts are optional
  }
}
let draftTimer = 0
watch(source, () => {
  window.clearTimeout(draftTimer)
  draftTimer = window.setTimeout(saveDraft, 400)
})

function cancel() {
  window.clearTimeout(draftTimer)
  saveDraft(false)
  emit('cancel')
}

/** Current names of users by ID, 50 per request; missing and hidden users are left out. */
async function userNames(ids: number[]): Promise<Record<number, string>> {
  const names: Record<number, string> = {}
  for (let i = 0; i < ids.length; i += 50) {
    const r = await actionGet<{ query: { users: Array<{ userid?: number, name?: string, missing?: boolean }> } }>({ action: 'query', list: 'users', ususerids: ids.slice(i, i + 50).join('|') })
    for (const u of r.query.users) if (u.userid && u.name && !u.missing) names[u.userid] = u.name
  }
  return names
}

/**
 * Mentions of a stored text back as units with the users' current names (a restored draft).
 * A user who is gone or hidden by now stays plain text, under the name the draft showed.
 */
async function loadNames() {
  const before = source.value
  const ids = tokenIds(before)
  if (!ids.length) return
  try {
    const names = await userNames(ids)
    if (source.value !== before) return
    const r = deserialize(serialize(text.value, units.value.filter((u) => names[u.id])), names)
    if (r.text === text.value && r.units.length === units.value.length) return
    text.value = r.text
    setUnits(r.units, r.text)
  } catch {
    // the draft keeps the names it had
  }
}

// ---- rate limit: count down every second while it lasts

const clock = ref(Date.now())
let clockTimer = 0
watch(() => store.state.rateLimitedUntil, (until) => {
  window.clearInterval(clockTimer)
  clock.value = Date.now()
  if (until > clock.value) {
    clockTimer = window.setInterval(() => {
      clock.value = Date.now()
      if (clock.value >= store.state.rateLimitedUntil) window.clearInterval(clockTimer)
    }, 1000)
  }
}, { immediate: true })

// ---- sending

async function send() {
  if (!canSend.value) return
  closeMentions()
  posting.value = true
  error.value = ''
  status.value = ''
  try {
    const c = await store.post(source.value, props.replyTo || null)
    text.value = ''
    setUnits([], '')
    status.value = c.status === 'held' ? t('posted-held') : t('posted')
    if (c.status === 'held') toast(t('posted-held'))
    emit('done')
    if (!reply.value && box.value && box.value.el) box.value.el.blur()
  } catch (e) {
    error.value = errorMessage(e, { anon: mw.user.isAnon() })
    nextTick(() => box.value && box.value.el && box.value.el.focus())
  } finally {
    posting.value = false
  }
}

function onKeydown(e: KeyboardEvent) {
  // A Chinese input method is composing: Enter and the arrows belong to it.
  if (e.isComposing || e.keyCode === 229) return
  // Backspace at the end of a mention removes all of it.
  const el = box.value && box.value.el
  const u = e.key === 'Backspace' && !e.ctrlKey && !e.metaKey && !e.altKey && el && el.selectionStart === el.selectionEnd
    ? units.value.find((x) => x.end === el.selectionStart)
    : null
  if (u) {
    e.preventDefault()
    replaceRange(u.start, u.end, '')
    return
  }
  if (mention.open && mention.items.length) {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      const n = mention.items.length
      mention.active = (mention.active + (e.key === 'ArrowDown' ? 1 : n - 1)) % n
      mention.keyed = true
      return
    }
    if (e.key === 'Enter' || e.key === 'Tab') {
      e.preventDefault()
      pickMention(mention.items[mention.active])
      return
    }
    if (e.key === 'Escape') {
      e.preventDefault()
      e.stopPropagation()
      closeMentions()
      return
    }
  }
  if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
    e.preventDefault()
    send()
  } else if (e.key === 'Escape' && reply.value && !text.value) {
    e.preventDefault()
    cancel()
  }
}

// The caret moved without typing (arrows, Home, End): the suggestion may no longer apply.
function onKeyup(e: KeyboardEvent) {
  if (/^(ArrowLeft|ArrowRight|Home|End|PageUp|PageDown)$/.test(e.key)) updateMentions()
}

function onFocusOut(e: FocusEvent) {
  const next = e.relatedTarget as Node | null
  // Moving into the toolbar or into a popover of this composer (emoji) keeps it open.
  if (next && ((field.value && field.value.contains(next)) || props.portal.contains(next))) return
  // Collapsing moves the comments below: wait until a click elsewhere has landed.
  afterPress(() => {
    const active = document.activeElement
    if (active && field.value && field.value.contains(active)) return
    focused.value = false
    window.setTimeout(closeMentions, 150)
  })
}

// ---- mention units

function setUnits(list: Unit[], value = text.value) {
  units.value = list.slice().sort((a, b) => a.start - b.start)
  last = value
  // A new text is a new step (and ends any Redo). The same text with other units (a pick) is not.
  if (!steps[step] || steps[step].text !== value) {
    steps.length = step + 1
    if (steps.length > 200) steps.shift()
    step = steps.length
  }
  steps[step] = { text: value, units: units.value }
}

/** Undo and Redo: the nearest step back or forward with this text, or -1. */
function stepFor(next: string, type: string): number {
  const dir = type === 'historyUndo' ? -1 : type === 'historyRedo' ? 1 : 0
  for (let i = step + dir; dir && i >= 0 && i < steps.length; i += dir) if (steps[i].text === next) return i
  return -1
}

/** The text became `next`, the caret at `caret`: the units follow it (shiftUnits()). */
function changed(next: string, caret: number, type = '') {
  const before = units.value
  const i = stepFor(next, type)
  if (i >= 0) step = i
  const kept = i >= 0 ? validUnits(next, steps[i].units) : shiftUnits(last, next, caret, before)
  const lost = before.find((u) => !kept.some((k) => k.id === u.id))
  const gained = kept.find((k) => !before.some((u) => u.id === k.id))
  if (lost || gained) note.value = lost ? t('mention-removed', lost.name) : t('mention-added', (gained as Unit).name)
  setUnits(kept, next)
}

function onInput(e: Event) {
  const el = e.target as HTMLTextAreaElement
  const type = (e as InputEvent).inputType || ''
  // Android keyboards send Backspace with key code 229, which onKeydown leaves alone: when it
  // took the last character of a mention, the rest goes too.
  const cut = type === 'deleteContentBackward' && last.length - el.value.length === 1
    ? units.value.find((u) => u.end - 1 === el.selectionEnd)
    : null
  // v-model waits for compositionend while an input method composes. The units follow the
  // textarea at once, so the text must too (v-model does not write the textarea meanwhile).
  text.value = el.value
  changed(el.value, el.selectionEnd, type)
  if (cut) replaceRange(cut.start, cut.end - 1, '')
  updateMentions()
}

/** Replaces start..end through the browser's editing where it can, so that Undo works. */
function replaceRange(start: number, end: number, insert: string) {
  const el = box.value && box.value.el
  if (el) {
    el.focus()
    el.setSelectionRange(start, end)
    // It fires input (onInput). False, or missing, where the browser cannot.
    if (typeof document.execCommand === 'function' && document.execCommand(insert ? 'insertText' : 'delete', false, insert)) return
  }
  const next = text.value.slice(0, start) + insert + text.value.slice(end)
  changed(next, start + insert.length)
  text.value = next
  nextTick(() => {
    if (!el) return
    el.focus()
    el.setSelectionRange(start + insert.length, start + insert.length)
  })
}

// ---- inserting text (emoji, @)

function insertAtCaret(insert: string) {
  const el = box.value && box.value.el
  replaceRange(el ? el.selectionStart : text.value.length, el ? el.selectionEnd : text.value.length, insert)
  nextTick(updateMentions)
}

function insertAt() {
  const el = box.value && box.value.el
  const before = el ? text.value.slice(0, el.selectionStart) : text.value
  insertAtCaret(before && !/\s$/.test(before) ? ' @' : '@')
}

// ---- @mention suggestions

// keyed: the active option was reached with the arrow keys (it then shows the focus ring).
const mention = reactive({ open: false, query: '', start: 0, items: [] as string[], active: 0, keyed: false })
let searchTimer = 0
let searchSeq = 0
const remote = ref<string[]>([])
// User IDs of the suggested names: comment authors carry theirs, list=allusers returns them.
const ids = new Map<string, number>()

function participants(): string[] {
  const names: string[] = []
  const d = store.data.value
  const visit = (c: Comment) => {
    if (c.author && c.author.name && !c.author.hidden && !c.author.anon && c.author.id && names.indexOf(c.author.name) < 0) {
      names.push(c.author.name)
      ids.set(c.author.name, c.author.id)
    }
    for (const r of c.replies || []) visit(r)
  }
  if (props.replyTo) visit(props.replyTo)
  if (d) d.pinned.concat(d.comments).forEach(visit)
  return names
}

function updateMentions() {
  const el = box.value && box.value.el
  if (!el || el.selectionStart !== el.selectionEnd) return closeMentions()
  const q = mentionQuery(text.value.slice(0, el.selectionStart))
  // Right after a mention, its own name is not a new query.
  if (!q || units.value.some((u) => u.start <= q.start && q.start < u.end)) return closeMentions()
  mention.query = q.query
  mention.start = q.start
  refreshItems()
  mention.open = true
  window.clearTimeout(searchTimer)
  if (q.query) {
    const seq = ++searchSeq
    searchTimer = window.setTimeout(() => {
      actionGet<{ query: { allusers: Array<{ userid: number, name: string }> } }>({ action: 'query', list: 'allusers', auprefix: userPrefix(q.query), aulimit: 8 })
        .then((r) => {
          if (seq !== searchSeq) return
          remote.value = r.query.allusers.map((u) => {
            ids.set(u.name, u.userid)
            return u.name
          })
          refreshItems()
        })
        .catch(() => undefined)
    }, 250)
  } else {
    remote.value = []
  }
}

function refreshItems() {
  mention.items = rankCandidates(mention.query, participants(), remote.value, user)
  if (mention.active >= mention.items.length) mention.active = 0
  // The suggestions are announced now: an earlier note must not come back when they close.
  if (mention.items.length) note.value = ''
}

function closeMentions() {
  mention.open = false
  mention.keyed = false
  mention.items = []
  mention.active = 0
}

/** Replaces the typed `@query` with a mention unit `@Name` and a space. */
function pickMention(name: string) {
  const el = box.value && box.value.el
  const caret = el ? el.selectionStart : text.value.length
  // Check again where the mention starts: never replace text the reader did not type as one.
  const q = mentionQuery(text.value.slice(0, caret))
  const id = ids.get(name)
  closeMentions()
  if (!q || !id) return
  replaceRange(q.start, caret, '@' + name + ' ')
  setUnits(validUnits(text.value, units.value.concat({ start: q.start, end: q.start + 1 + name.length, id, name })))
  note.value = t('mention-added', name)
}

onMounted(() => {
  if (reply.value && box.value && box.value.el) box.value.el.focus()
  loadNames()
})
onBeforeUnmount(() => {
  window.clearTimeout(searchTimer)
  window.clearInterval(clockTimer)
})

// ---- the highlight: the same text behind the textarea, its mentions underlined (decoration
// only: screen readers get note and the list of mentions)

const mirror = ref<HTMLElement | null>(null)
// Plain text and mentions in turn.
const mirrorParts = computed(() => {
  const parts: string[] = []
  let at = 0
  for (const u of units.value) {
    parts.push(text.value.slice(at, u.start), text.value.slice(u.start, u.end))
    at = u.end
  }
  // A trailing newline takes a line in the textarea: the same here.
  return parts.concat(text.value.slice(at) + '\u200B')
})
// Once the textarea has its new size: its scroll position, and its width when a scrollbar
// narrows it (otherwise the mirror follows the box).
function fitMirror() {
  const el = box.value && box.value.el
  if (!el || !mirror.value) return
  mirror.value.style.width = el.scrollHeight > el.clientHeight ? el.clientWidth + 'px' : ''
  mirror.value.scrollTop = el.scrollTop
}
watch(units, () => requestAnimationFrame(fitMirror), { flush: 'post', immediate: true })
const unitNames = computed(() => units.value.map((u) => u.name).join(t('list-separator')))

// Screen readers hear the highlighted suggestion (the list lives outside the textarea).
const mentionStatus = computed(() =>
  mention.open && mention.items.length ? t('mention-active', displayName(mention.items[mention.active], store.canModerate.value), mention.active + 1, mention.items.length) : '',
)
</script>

<template>
  <div
    ref="field"
    class="ext-glowthread-composer gt:flex gt:items-start gt:gap-(--gt-v-avatar-gap)"
    @focusin="focused = true"
    @focusout="onFocusOut"
  >
    <Avatar v-if="!reply" :name="user" class="gt:mt-0.5" />
    <div class="gt:min-w-0 gt:flex-1">
      <div
        ref="frame"
        class="ext-glowthread-composer-field gt:rounded-surface gt:border gt:bg-surface gt:transition-colors"
        :class="focused ? 'gt:border-accent' : 'gt:border-border-control'"
      >
        <label :for="uid" class="gt-sr-only">{{ placeholder }}</label>
        <div class="gt:relative">
          <div
            v-if="units.length"
            ref="mirror"
            class="gt:pointer-events-none gt:absolute gt:inset-0 gt:overflow-hidden gt:px-3 gt:py-2 gt:break-words gt:whitespace-pre-wrap gt:text-body gt:leading-[var(--gt-v-line-height-body)] gt:text-transparent gt:forced-color-adjust-none"
            aria-hidden="true"
          ><template v-for="(p, i) in mirrorParts" :key="i"><mark v-if="i % 2" class="gt:bg-transparent gt:text-transparent gt:shadow-[inset_0_-2px_0_var(--gt-v-color-accent)]">{{ p }}</mark><template v-else>{{ p }}</template></template></div>
          <Textarea
            :id="uid"
            ref="box"
            v-model="text"
            :rows="expanded ? 3 : 1"
            :max-rows="12"
            :placeholder="placeholder"
            :readonly="posting"
            :aria-invalid="remaining < 0 ? 'true' : undefined"
            :aria-describedby="uid + '-help ' + uid + '-units'"
            aria-autocomplete="list"
            :aria-controls="mention.open && mention.items.length ? uid + '-mentions' : undefined"
            :aria-activedescendant="mention.open && mention.items.length ? uid + '-mentions-' + mention.active : undefined"
            class="gt:relative gt:px-3 gt:py-2"
            @keydown="onKeydown"
            @keyup="onKeyup"
            @input="onInput"
            @scroll="fitMirror"
            @click="updateMentions"
          />
        </div>
        <!-- mousedown.prevent: Safari and Firefox on macOS do not focus buttons on click, so
             the textarea would blur and collapse the toolbar before the click lands. -->
        <div v-if="expanded" class="ext-glowthread-composer-toolbar gt:flex gt:items-center gt:gap-1 gt:border-t gt:border-border gt:py-1 gt:pr-1 gt:pl-1.5" @mousedown.prevent>
          <EmojiPicker @pick="insertAtCaret" />
          <button
            type="button"
            class="gt-hit gt:inline-flex gt:size-8 gt:items-center gt:justify-center gt:rounded-control gt:text-fg-muted gt:hover:bg-surface-subtle gt:hover:text-fg"
            :aria-label="t('mention')"
            @click="insertAt"
          >
            <AtSign class="gt:size-icon-lg" aria-hidden="true" />
          </button>
          <span
            v-if="showCount"
            class="gt-num gt:ml-auto gt:text-meta"
            :class="remaining < 0 ? 'gt:font-medium gt:text-danger' : 'gt:text-fg-muted'"
          >
            {{ num(length) }} / {{ num(max) }}
          </span>
          <Button v-if="reply" variant="ghost" size="md" :class="showCount ? '' : 'gt:ml-auto'" @click="cancel">
            {{ t('cancel') }}
          </Button>
          <Button
            variant="primary"
            size="md"
            class="ext-glowthread-send"
            :class="showCount || reply ? '' : 'gt:ml-auto'"
            :disabled="!canSend"
            :aria-keyshortcuts="'Control+Enter Meta+Enter'"
            @click="send"
          >
            <Spinner v-if="posting" />
            <Send v-else class="gt-flip-rtl gt:size-icon" aria-hidden="true" />
            {{ posting ? t('sending') : t('send') }}
          </Button>
        </div>
      </div>
      <p :id="uid + '-help'" class="gt:text-meta" aria-live="polite">
        <span v-if="waitSeconds > 0" class="gt:mt-1.5 gt:block gt:text-warning">{{ t('error-ratelimited-wait', waitSeconds) }}</span>
        <span v-else-if="remaining < 0" class="gt:mt-1.5 gt:block gt:text-danger">{{ t('error-too-long', num(max)) }}</span>
        <span v-else-if="error" class="gt:mt-1.5 gt:block gt:text-danger" role="alert">{{ error }}</span>
        <span v-else-if="status" class="gt-sr-only">{{ status }}</span>
        <span v-else-if="expanded && focused" class="gt:mt-1.5 gt:hidden gt:text-fg-muted gt:sm:block">{{ t('send-hint') }}</span>
      </p>
    </div>
    <span class="gt-sr-only" aria-live="polite">{{ mentionStatus || note }}</span>
    <span :id="uid + '-units'" class="gt-sr-only">{{ unitNames ? t('mention-units', unitNames) : '' }}</span>
    <MentionList
      v-if="mention.open && mention.items.length"
      :anchor="frame"
      :items="mention.items"
      :reveal="store.canModerate.value"
      :active="mention.active"
      :keyed="mention.keyed"
      :list-id="uid + '-mentions'"
      :portal="portal"
      :label="t('mention-list')"
      @pick="pickMention"
      @hover="(i) => { mention.active = i; mention.keyed = false }"
    />
  </div>
</template>
