<script setup lang="ts">
import { computed, inject, ref } from 'vue'
import type { Ref } from 'vue'
import { Check, Clock, CornerDownRight, Eraser, ExternalLink, Flag, Pin, PinOff, RotateCcw, Trash2 } from '@lucide/vue'
import type { FilterTestResult, QueueItem } from '@/shared/types'
import { Avatar } from '@/ui/avatar'
import { Badge } from '@/ui/badge'
import { Button } from '@/ui/button'
import CommentBody from '@/shared/CommentBody.vue'
import RelativeTime from '@/shared/RelativeTime.vue'
import ItemSelect from './ItemSelect.vue'
import FrequentBadge from './FrequentBadge.vue'
import { actionPost } from '@/lib/api'
import { errorMessage } from '../errors'
import { plainText } from '@/lib/segments'
import { storedText } from '@/lib/text'
import { commentPermalink } from '@/lib/wiki'
import { t, tParts, num } from '@/lib/i18n'
import { useHub } from '../context'
import { actionFor, groupReports, openReports } from '../moderation'
import type { Kind } from '../moderation'
import { accountAge } from '../format'
import { restoreLabel, restoreOutcome } from '@/shared/restore'
import { revealInvisible } from '@/lib/names'

/**
 * One comment in the queue, with everything needed to judge it in place: page, author
 * and how old the account is, floor and time, the comment it answers, its text, the
 * reports grouped by reason with their reporters, why it is held, and the actions with
 * their keys. The article is the unit j and k move between (roving tabindex).
 */
const props = defineProps<{
  item: QueueItem
  focused: boolean
  busy: boolean
  stale: boolean
  /** Reporters behind many loaded reports: name -> items they reported (a stable record). */
  frequent: Record<string, number>
  /** The queue tab: a chip that only repeats it is left out. */
  tab: string
}>()
const hub = useHub()
const now = inject<Ref<number>>('gt-now', ref(Date.now()))

const c = computed(() => props.item.comment)
const id = computed(() => c.value.id)
const author = computed(() => (c.value.author && !c.value.author.hidden ? c.value.author.name : null))
// For moderators, characters that show nothing are written out (lookalike names).
const shownAuthor = computed(() => (author.value ? revealInvisible(author.value) : null))
// Moderators get the author's registration and block with each comment.
const age = computed(() => (c.value.author.registration ? accountAge(c.value.author.registration, now.value) : null))
const blocked = computed(() => c.value.author.blocked || null)
const pageTitle = computed(() => props.item.page.title)
const reports = computed(() => groupReports(props.item))
const reportCount = computed(() => Math.max(openReports(c.value), props.item.reports.length))
const notes = computed(() => props.item.reports.filter((r) => r.note).slice(0, 2))
const holds = computed(() => (c.value.mod ? c.value.mod.holdReasons : []))
// The word-filter lines that held it, as the server recorded them.
const matches = computed(() => (c.value.mod && c.value.mod.holdMatches) || [])
// A deleted comment that was once held: restoring it publishes it, and the label says so.
// What Restore does: back to held, or public. A deleted comment that was held, or may
// have been, says so with its hold reasons.
const restore = computed(() => (c.value.status === 'deleted' ? restoreOutcome(c.value) : 'plain'))
const wasHeld = computed(() => c.value.status === 'deleted' && holds.value.length > 0 && restore.value !== 'plain')
const pass = computed(() => actionFor('pass', c.value))
const can = (kind: Kind) => !!actionFor(kind, c.value)
const disabled = computed(() => props.busy || hub.blocked.value)
const headId = computed(() => 'gt-q-h-' + id.value)
const context = computed(() => props.item.context)
const contextText = computed(() => {
  const text = context.value ? plainText(context.value.segments).replace(/\s+/g, ' ').trim() : ''
  const chars = Array.from(text)
  return chars.length > 80 ? chars.slice(0, 79).join('') + '…' : text
})
const contextName = computed(() => {
  const a = context.value && context.value.author
  return a && !a.hidden && a.name ? revealInvisible(a.name) : t('user-hidden')
})

function act(kind: Kind) {
  hub.queue.focus(id.value)
  hub.act(kind, [id.value])
}

function openUser(name: string | null) {
  if (name) hub.showRecord({ type: 'user', name }, props.item)
}

function onlyReporter(name: string) {
  const r = hub.route.value
  if (r.view === 'queue') hub.navigate({ ...r, tab: 'reported', reporter: name, subject: null })
}

function openPage() {
  hub.showRecord({ type: 'page', id: props.item.page.id, title: pageTitle.value || '' }, props.item)
}

// Which rule of the word filter held this comment: asked on demand (glowthreadfiltertest).
const rule = ref<FilterTestResult | null>(null)
const ruleError = ref('')
const ruleBusy = ref(false)
async function findRule() {
  ruleBusy.value = true
  ruleError.value = ''
  try {
    rule.value = await actionPost<FilterTestResult>('glowthreadfiltertest', { text: storedText(c.value.segments) || '-' })
  } catch (e) {
    ruleError.value = errorMessage(e)
  } finally {
    ruleBusy.value = false
  }
}
</script>

<template>
  <article
    :id="'gt-q-' + id"
    :tabindex="focused ? 0 : -1"
    aria-setsize="-1"
    :aria-labelledby="headId"
    :data-comment="id"
    :data-glowthread-status="c.status"
    class="ext-glowthread-queue-item gt-q-item gt:relative gt:grid gt:grid-cols-[auto_minmax(0,1fr)] gt:gap-x-3 gt:border-b gt:border-border gt:px-3 gt:py-2.5 gt:focus-visible:outline-offset-[calc(var(--gt-v-focus-ring-width)*-1)] gt:sm:grid-cols-[auto_auto_minmax(0,1fr)]"
    :class="focused ? 'gt-q-focused' : ''"
    @click="hub.queue.focus(id)"
    @focusin="hub.queue.focus(id)"
  >
    <ItemSelect :id="id" :label="t('admin-select', shownAuthor || t('user-hidden'), pageTitle || '', c.seq)" />
    <span class="gt:hidden gt:sm:block">
      <Avatar :name="author" :generic="!author || c.author.anon" />
    </span>

    <div class="gt:min-w-0">
      <header :id="headId" class="gt:flex gt:min-h-8 gt:flex-wrap gt:items-center gt:gap-x-1.5 gt:gap-y-0.5 gt:text-meta">
        <!-- "$1 on $2": the author (with account age and block), then the page. -->
        <template v-for="(p, i) in tParts('admin-on-page', 2)" :key="i">
          <template v-if="p === 1">
            <button
              v-if="author"
              type="button"
              class="ext-glowthread-name gt-hit gt:inline-flex gt:max-w-[14em] gt:text-body gt:font-medium gt:text-fg gt:hover:underline"
              dir="auto"
              :title="shownAuthor !== author ? shownAuthor || undefined : undefined"
              @click.stop="openUser(author)"
            >
              <span class="gt:truncate">{{ shownAuthor }}</span>
            </button>
            <span v-else class="gt:text-body gt:text-fg-muted">{{ t('user-hidden') }}</span>
            <span v-if="age" class="gt-num" :class="age.young ? 'gt:font-semibold gt:text-fg' : 'gt:text-fg-muted'">{{ age.text }}</span>
            <Badge v-if="blocked" tone="danger">{{ blocked === 'partial' ? t('admin-blocked-partial') : t('admin-blocked-badge') }}</Badge>
          </template>
          <button
            v-else-if="p === 2"
            type="button"
            class="gt-hit gt:inline-flex gt:max-w-[16em] gt:text-fg gt:hover:underline"
            dir="auto"
            @click.stop="openPage"
          >
            <span class="gt:truncate">{{ pageTitle || t('admin-page-gone') }}</span>
          </button>
          <span v-else class="gt:text-fg-muted">{{ p }}</span>
        </template>
        <span class="gt:whitespace-nowrap gt:text-fg-muted">
          <span class="gt-num">#{{ c.seq }}</span>
          <span aria-hidden="true"> · </span>
          <RelativeTime :iso="c.created" />
        </span>
        <span class="gt:flex gt:flex-wrap gt:gap-1 gt:sm:ml-auto gt:sm:pl-2">
          <Badge v-if="c.status === 'held' && tab !== 'held'" tone="warning">{{ t('held') }}</Badge>
          <Badge v-if="reportCount && tab !== 'reported'" tone="danger">{{ t('reported-count', num(reportCount)) }}</Badge>
          <Badge v-if="c.status === 'deleted' && tab !== 'deleted'">{{ t('admin-status-deleted') }}</Badge>
          <Badge v-if="c.status === 'erased'">{{ t('status-erased') }}</Badge>
          <Badge v-if="c.pinned" tone="accent">{{ t('pinned') }}</Badge>
        </span>
      </header>

      <div v-if="context" class="gt:mt-0.5 gt:flex gt:min-w-0 gt:items-center gt:gap-1.5 gt:text-meta gt:text-fg-muted">
        <CornerDownRight class="gt-flip-rtl gt:size-icon-sm gt:shrink-0" aria-hidden="true" />
        <span class="gt:min-w-0 gt:truncate" dir="auto">{{ t('admin-context', contextName, contextText || t('admin-context-empty')) }}</span>
        <Badge v-if="context.status !== 'visible'" class="gt:shrink-0">{{ context.status === 'held' ? t('held') : t('admin-status-deleted') }}</Badge>
      </div>

      <div class="gt:mt-1">
        <div v-if="c.status === 'erased'" class="gt:text-body gt:text-fg-muted">{{ t('erased-text') }}</div>
        <CommentBody v-else :segments="c.segments" />
      </div>

      <!-- Why it is here, then what to do about it: the actions always get their own row, left
           aligned under the text, Approve and Delete (or Restore) first so they sit at the same place
           in every item, then the occasional ones, View in context last. -->
      <div v-if="reports.length || c.status === 'held' || wasHeld || stale" class="gt:mt-2 gt:flex gt:min-w-0 gt:flex-col gt:gap-1.5">
        <div v-if="reports.length" class="gt:flex gt:flex-col gt:gap-1 gt:text-meta">
          <div v-for="g in reports" :key="g.reason" class="gt:flex gt:flex-wrap gt:items-center gt:gap-x-1.5 gt:gap-y-0.5">
            <Flag class="gt:size-icon-sm gt:shrink-0 gt:text-danger" aria-hidden="true" />
            <span class="gt:font-medium gt:text-fg">{{ t('reason-' + g.reason) }}</span>
            <span class="gt-num gt:text-fg-muted">×{{ num(g.count) }}</span>
            <template v-if="g.reporters.length">
              <template v-for="(p, j) in tParts('admin-reported-by')" :key="j">
                <!-- Inline text, not flex items: the separator keeps its space and screen readers
                     hear it between the names. -->
                <span v-if="p === 1" class="gt:text-fg-muted"><template v-for="(name, i) in g.reporters" :key="name"><button type="button" class="gt-hit gt:text-fg-muted gt:hover:text-fg gt:hover:underline" dir="auto" @click.stop="openUser(name)">{{ revealInvisible(name) }}</button><FrequentBadge :name="name" :counts="frequent" class="gt:align-middle" @filter="onlyReporter" /><template v-if="i < g.reporters.length - 1">{{ t('list-separator') }}</template></template></span>
                <span v-else class="gt:text-fg-muted">{{ p }}</span>
              </template>
              <span v-if="g.count > g.reporters.length" class="gt:text-fg-muted">{{ t('admin-reported-more', num(g.count - g.reporters.length)) }}</span>
            </template>
          </div>
          <div v-for="r in notes" :key="r.id" class="gt:pl-5 gt:font-text gt:text-fg-muted" dir="auto">“{{ r.note }}”</div>
        </div>

        <div v-if="c.status === 'held' || wasHeld" class="gt:flex gt:flex-wrap gt:items-center gt:gap-x-2 gt:gap-y-1 gt:text-meta gt:text-fg-muted">
          <Clock class="gt:size-icon-sm gt:shrink-0 gt:text-warning" aria-hidden="true" />
          <span><template v-for="(p, i) in tParts(wasHeld ? 'mod-was-held' : 'mod-held-because')" :key="i"><span v-if="p === 1" class="gt:font-medium gt:text-fg">{{ holds.map((h) => t('hold-' + h)).join(t('list-separator')) || t('hold-unknown') }}</span><template v-else>{{ p }}</template></template></span>
          <!-- The lines that matched, as recorded with the hold; the filter test is the fallback. -->
          <span v-if="matches.length" class="gt:text-fg">
            {{ t('admin-rule-matched', matches.map((m) => t('admin-rule-line', m.line, m.rule)).join(t('list-separator'))) }}
          </span>
          <template v-else-if="holds.indexOf('filter') >= 0 && hub.config.canConfigure">
            <span v-if="rule && rule.matches.length" class="gt:text-fg">
              {{ t('admin-rule-matched', rule.matches.map((m) => t('admin-rule-line', m.line, m.rule)).join(t('list-separator'))) }}
            </span>
            <span v-else-if="rule" class="gt:text-fg-muted">{{ t('admin-rule-none') }}</span>
            <span v-else-if="ruleError" class="gt:text-danger">{{ ruleError }}</span>
            <button v-else type="button" class="gt-hit gt-link gt:font-medium" :disabled="ruleBusy" @click.stop="findRule">
              {{ t('admin-rule-find') }}
            </button>
          </template>
        </div>

        <div v-if="stale" class="ext-glowthread-notice gt:rounded-control gt:bg-warning-subtle gt:px-2.5 gt:py-1.5 gt:text-meta gt:text-warning" role="note" data-glowthread-tone="warning">
          {{ t('admin-item-stale') }}
        </div>
      </div>

      <div class="gt:mt-2 gt:flex gt:flex-wrap gt:items-center gt:gap-1.5">
        <Button
          v-if="pass"
          size="sm"
          :disabled="disabled"
          class="gt-tip"
          :data-tip="pass === 'approve' ? t('admin-pass-approve-help') : t('admin-pass-dismiss-help')"
          @click.stop="act('pass')"
        >
          <Check class="gt:size-icon-sm" aria-hidden="true" />{{ t('approve') }}<kbd class="gt-kbd gt-kbd-hint" aria-hidden="true">a</kbd>
        </Button>
        <Button v-if="can('delete')" size="sm" variant="danger" :disabled="disabled" @click.stop="act('delete')">
          <Trash2 class="gt:size-icon-sm" aria-hidden="true" />{{ t('delete') }}<kbd class="gt-kbd gt-kbd-hint" aria-hidden="true">d</kbd>
        </Button>
        <Button v-if="can('restore')" size="sm" :disabled="disabled" @click.stop="act('restore')">
          <RotateCcw class="gt:size-icon-sm" aria-hidden="true" />{{ t(restoreLabel(restore)) }}<kbd class="gt-kbd gt-kbd-hint" aria-hidden="true">r</kbd>
        </Button>
        <Button v-if="c.status === 'held' && can('dismiss')" size="sm" variant="ghost" :disabled="disabled" @click.stop="act('dismiss')">
          {{ t('dismiss') }}
        </Button>
        <Button v-if="can('pin')" size="sm" variant="ghost" :disabled="disabled" @click.stop="act('pin')">
          <Pin class="gt:size-icon-sm" aria-hidden="true" />{{ t('pin') }}
        </Button>
        <Button v-if="can('unpin')" size="sm" variant="ghost" :disabled="disabled" @click.stop="act('unpin')">
          <PinOff class="gt:size-icon-sm" aria-hidden="true" />{{ t('unpin') }}
        </Button>
        <Button v-if="hub.config.canErase && can('erase')" size="sm" variant="danger" :disabled="disabled" @click.stop="hub.confirmErase(item)">
          <Eraser class="gt:size-icon-sm" aria-hidden="true" />{{ t('admin-erase') }}
        </Button>
        <!-- An icon on phones, so the row stays one line. -->
        <Button as="a" size="sm" variant="ghost" :href="commentPermalink(id)" target="_blank" rel="noopener" :aria-label="t('admin-context-open')" @click.stop>
          <span class="gt:hidden gt:sm:inline">{{ t('admin-context-open') }}</span><ExternalLink class="gt:size-icon-sm" aria-hidden="true" />
        </Button>
      </div>
    </div>
  </article>
</template>
