<script setup lang="ts">
import { computed, inject, nextTick, ref, watch } from 'vue'
import type { Comment, QueueItem } from '@/shared/types'
import { Avatar } from '@/ui/avatar'
import { Badge } from '@/ui/badge'
import { Button } from '@/ui/button'
import UserName from '@/shared/UserName.vue'
import RelativeTime from '@/shared/RelativeTime.vue'
import CommentBody from '@/shared/CommentBody.vue'
import LikeButton from './LikeButton.vue'
import CommentMenu from './CommentMenu.vue'
import { Checkbox, Composer, ModNotes } from '../lazy'
import { FOCUS_HEADING, useOverlays, useStore } from '../context'
import { showError } from '../actions'
import { t, num } from '@/lib/i18n'
import { toast } from '@/ui/toast'
import { Spinner } from '@/ui/spinner'
import { errorMessage } from '@/lib/errors'
import { loginUrl } from '@/lib/wiki'
import { displayName } from '@/lib/names'

/**
 * One comment: avatar, name, floor and time, text, like and Reply, the ⋯ menu, and up to
 * two levels of replies below a hairline rule. Replies not loaded yet are shown in place with
 * "Show N more replies" (paged from the server), and a conversation the reader opened folds
 * again with "Hide replies". `anchor` gives the element its permalink ID (gt-<id>); copies
 * of a comment (the linked conversation) render without it.
 * The icons are Lucide's, written out as SVG because every comment draws them (cheaper than
 * the components). tests/icons.test.ts keeps the markup equal to Lucide's.
 */
const props = withDefaults(defineProps<{
  comment: Comment
  depth?: number
  anchor?: boolean
  portal: HTMLElement
  focus?: number
  /** Where this copy is shown: the list, the linked conversation or the moderation filter. */
  surface?: 'list' | 'linked' | 'queue'
  /** In the moderation filter: the open reports with reporters and notes. */
  reports?: QueueItem['reports']
}>(), {
  depth: 0,
  anchor: true,
  focus: 0,
  surface: 'list',
})
const store = useStore()
const overlays = useOverlays()
const c = computed(() => props.comment)
const replyButton = ref<{ $el: HTMLElement } | null>(null)
const menu = ref<{ focus(): void } | null>(null)
const confirmBox = ref<HTMLElement | null>(null)
const headingId = computed(() => 'gt-h-' + props.surface + '-' + c.value.id)

const key = computed(() => props.surface + ':' + c.value.id)
const replying = computed(() => store.state.replyingTo === key.value)
const confirming = computed(() => store.state.confirmingDelete === key.value)
const deleting = ref(false)
const fresh = computed(() => store.state.fresh.indexOf(c.value.id) >= 0)
const selected = computed(() => store.state.selected.indexOf(c.value.id) >= 0)
const removed = computed(() => c.value.status === 'deleted' || c.value.status === 'erased')
// On phones the list folds replies behind "Show N replies" (the reader's own new reply stays).
// The reader can fold any conversation again, and unfold it.
const fold = computed(() => store.state.folds[c.value.id])
const folded = computed(() => props.depth === 0 && (fold.value !== undefined ? fold.value : props.surface === 'list' && overlays.phone.value))
const shownReplies = computed(() => {
  const all = c.value.replies || []
  return folded.value ? all.filter((r) => store.state.fresh.indexOf(r.id) >= 0) : all
})
// Bulk actions act on what is shown: replies that fold away leave the selection.
watch(folded, (f) => {
  if (f) store.dropFolded(c.value)
})
const slot = computed(() => store.state.replies[c.value.id])
const loadingReplies = computed(() => !!slot.value && slot.value.status === 'loading')
// What the link under the replies stands for: folded, every reply not shown, otherwise the
// replies the server has not sent yet (a shown reply with more of its own has its own link).
const hiddenReplies = computed(() => (folded.value
  ? Math.max(0, (c.value.totalReplies || 0) - shownReplies.value.reduce((n, r) => n + 1 + countShown(r), 0))
  : store.hiddenReplies(c.value)))
const repliesId = computed(() => 'gt-r-' + props.surface + '-' + c.value.id)
const repliesBox = ref<HTMLElement | null>(null)
const toggle = ref<HTMLElement | null>(null)
// "Reply to @Bob" when the author answered someone other than the comment it is shown under.
const replyTarget = computed(() => {
  const r = c.value.replyTo
  if (!r || props.depth === 0 || r.id === c.value.parentId) return null
  if (!r.visible) return { name: null as string | null }
  return { name: r.author && !r.author.hidden && r.author.name ? displayName(r.author.name, store.canModerate.value) : null }
})

function countShown(x: Comment): number {
  return (x.replies || []).reduce((n, r) => n + 1 + countShown(r), 0)
}

function startReply() {
  if (mw.user.isAnon()) {
    toast(t('reply-login'), { action: { label: t('login'), onClick: () => { location.href = loginUrl() } } })
    return
  }
  store.state.replyingTo = key.value
}

function endReply() {
  store.state.replyingTo = null
  nextTick(() => replyButton.value && replyButton.value.$el && replyButton.value.$el.focus())
}

function startDelete() {
  store.state.confirmingDelete = key.value
  // Move focus into the confirmation, so keyboard and screen reader users meet it.
  nextTick(() => {
    const cancel = confirmBox.value && confirmBox.value.querySelector('button')
    if (cancel) (cancel as HTMLButtonElement).focus()
  })
}

function cancelDelete() {
  store.state.confirmingDelete = null
  nextTick(() => menu.value && menu.value.focus())
}

// ---- replies shown in place

// The reader opened this conversation (unfolded it or loaded more): it can fold again.
const canHide = computed(() => props.depth === 0 && !folded.value && fold.value === false && shownReplies.value.length > 0)

/** Keyboard and screen reader users continue from the first reply that just appeared. */
function focusReply(id: number) {
  const el = repliesBox.value && repliesBox.value.querySelector<HTMLElement>('[data-comment="' + id + '"]')
  if (!el) return
  el.setAttribute('tabindex', '-1')
  el.focus()
}

async function showMore() {
  if (loadingReplies.value) return
  if (props.depth === 0) {
    const wasFolded = folded.value
    store.state.folds[c.value.id] = false
    // Unfolding may already show everything that was loaded.
    if (wasFolded && !store.hiddenReplies(c.value)) {
      await nextTick()
      const first = (c.value.replies || [])[0]
      if (first) focusReply(first.id)
      return
    }
  }
  try {
    const added = await store.loadReplies(c.value)
    await nextTick()
    if (added.length) focusReply(added[0])
  } catch {
    // Shown under the replies with Try again.
  }
}

function hideReplies() {
  store.state.folds[c.value.id] = true
  // "Show N replies" takes the place of the replies: focus and scroll stay with it.
  nextTick(() => toggle.value && toggle.value.focus())
}

const focusHeading = inject(FOCUS_HEADING, () => undefined)

async function confirmDelete() {
  deleting.value = true
  try {
    await store.deleteOwn(c.value)
    toast(t('deleted-own'))
    // The comment is gone: continue from the section heading.
    nextTick(focusHeading)
  } catch (e) {
    showError(e)
  } finally {
    deleting.value = false
  }
}

const canReply = computed(() => c.value.status === 'visible' && (c.value.viewer.canReply || mw.user.isAnon()) && store.data.value && store.data.value.page.state === 'open')
</script>

<template>
  <article
    :id="anchor ? 'gt-' + c.id : undefined"
    :data-comment="c.id"
    :data-pinned="c.pinned && depth === 0 ? '' : undefined"
    :data-glowthread-depth="depth"
    :data-glowthread-status="c.status"
    :data-glowthread-pinned="c.pinned && depth === 0 ? '' : undefined"
    :data-glowthread-own="c.viewer.own ? '' : undefined"
    class="gt-comment ext-glowthread-comment"
    :aria-labelledby="headingId"
  >
    <!-- The comment itself; its replies follow below, so a highlight covers this row only. -->
    <div
      class="ext-glowthread-comment-row gt-comment-row gt:flex gt:rounded-control"
      :class="[
        depth === 0 ? 'gt:gap-(--gt-v-avatar-gap)' : 'gt:gap-2',
        fresh ? 'gt-anim-enter' : '',
        focus === c.id ? 'gt-highlight' : '',
      ]"
    >
      <Checkbox
        v-if="store.state.manage"
        :model-value="selected"
        class="gt:mt-2"
        :aria-label="t('select-comment', c.seq)"
        @update:model-value="store.toggleSelected(c.id)"
      />
      <Avatar :name="c.author.anon ? null : c.author.name" :size="depth === 0 ? 'md' : 'sm'" />
      <div class="gt:min-w-0 gt:flex-1">
        <header class="gt:flex gt:min-h-6 gt:items-center gt:gap-x-2">
          <h3 :id="headingId" class="gt:min-w-0 gt:truncate gt:text-body gt:font-medium">
            <UserName :author="c.author" :reveal="store.canModerate.value" />
          </h3>
          <Badge v-if="c.pinned && depth === 0" tone="accent">{{ t('pinned') }}</Badge>
          <Badge v-if="c.status === 'held' && c.viewer.own && !c.mod" tone="warning">{{ t('held-own') }}</Badge>
          <Badge v-else-if="c.status === 'held'" tone="warning">{{ t('held') }}</Badge>
          <Badge v-if="c.mod && c.mod.openReports > 0" tone="danger">{{ t('reported-count', c.mod.openReports) }}</Badge>
          <Badge v-if="c.status === 'deleted'" tone="neutral">{{ t('status-deleted') }}</Badge>
          <Badge v-if="c.status === 'erased'" tone="neutral">{{ t('status-erased') }}</Badge>
          <span v-if="replyTarget" class="gt:hidden gt:min-w-0 gt:truncate gt:text-meta gt:text-fg-muted gt:sm:inline">
            {{ replyTarget.name ? t('reply-to', replyTarget.name) : t('reply-to-removed') }}
          </span>
          <span class="ext-glowthread-meta gt-num gt:ml-auto gt:flex gt:shrink-0 gt:items-center gt:gap-1 gt:text-meta gt:text-fg-muted">
            <span aria-hidden="true">#{{ num(c.seq) }}</span><span class="gt-sr-only">{{ t('floor-label', c.seq) }}</span>
            <span aria-hidden="true">·</span>
            <RelativeTime :iso="c.created" />
          </span>
          <CommentMenu ref="menu" :comment="c" @delete="startDelete" />
        </header>

        <p v-if="replyTarget" class="gt:text-meta gt:text-fg-muted gt:sm:hidden">
          {{ replyTarget.name ? t('reply-to', replyTarget.name) : t('reply-to-removed') }}
        </p>
        <p v-if="c.status === 'erased'" class="gt:text-body gt:text-fg-muted">{{ t('erased-text') }}</p>
        <CommentBody v-else :segments="c.segments" :class="removed ? 'gt:opacity-60' : ''" />
        <p v-if="c.status === 'held' && c.viewer.own && !c.mod" class="gt:mt-1 gt:text-meta gt:text-fg-muted">{{ t('held-own-note') }}</p>
        <!-- Moderators only (the second module). -->
        <ModNotes v-if="c.mod" :comment="c" :reports="reports" />

        <div v-if="confirming" ref="confirmBox" class="gt:mt-2 gt:flex gt:flex-wrap gt:items-center gt:gap-2 gt:rounded-control gt:bg-danger-subtle gt:px-3 gt:py-2" role="group" :aria-label="t('delete-confirm')">
          <p class="gt:mr-auto gt:text-body gt:text-danger">{{ t('delete-confirm') }}</p>
          <Button size="sm" variant="ghost" @click="cancelDelete">{{ t('cancel') }}</Button>
          <Button size="sm" variant="danger" :disabled="deleting" @click="confirmDelete">{{ t('delete') }}</Button>
        </div>

        <footer v-if="!removed" class="ext-glowthread-actions gt:mt-1 gt:flex gt:items-center gt:gap-4">
          <LikeButton :comment="c" />
          <Button
            v-if="canReply"
            ref="replyButton"
            class="ext-glowthread-reply"
            variant="ghost"
            size="inline"
            :aria-expanded="replying ? 'true' : 'false'"
            @click="replying ? endReply() : startReply()"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-message-square gt:size-icon" aria-hidden="true"><path d="M22 17a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 21.286V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z" /></svg>{{ t('reply') }}
          </Button>
        </footer>

        <div v-if="replying" class="gt:mt-2">
          <Composer :reply-to="c" :portal="portal" @done="endReply" @cancel="endReply" />
        </div>

      </div>
    </div>

    <div
      v-if="shownReplies.length || hiddenReplies || (slot && slot.status === 'error')"
      :id="repliesId"
      ref="repliesBox"
      class="ext-glowthread-replies gt:mt-3 gt:flex gt:flex-col gt:gap-3"
      :class="depth === 0 ? 'gt:ml-(--gt-v-reply-indent) gt:border-l gt:border-border gt:pl-3' : 'gt:ml-[calc(var(--gt-v-avatar-size-small)_+_var(--gt-v-space)_*_2)]'"
      :aria-busy="loadingReplies ? 'true' : undefined"
    >
        <CommentItem
          v-for="r in shownReplies"
          :key="r.id"
          :comment="r"
          :depth="depth + 1"
          :anchor="anchor"
          :portal="portal"
          :focus="focus"
          :surface="surface"
        />
        <div v-if="hiddenReplies || canHide" class="gt:flex gt:flex-wrap gt:items-center gt:gap-x-4 gt:gap-y-1" aria-live="polite">
          <button
            v-if="hiddenReplies"
            ref="toggle"
            type="button"
            class="ext-glowthread-replies-toggle gt-hit gt:inline-flex gt:items-center gt:gap-1 gt:text-meta gt:font-medium gt:text-accent-text gt:hover:underline"
            :aria-expanded="folded ? 'false' : undefined"
            :aria-controls="folded ? repliesId : undefined"
            :aria-disabled="loadingReplies ? 'true' : undefined"
            @click="showMore"
          >
            <template v-if="loadingReplies"><Spinner />{{ t('loading') }}</template>
            <template v-else>
              {{ folded && !shownReplies.length ? t('replies-show', num(hiddenReplies)) : t('replies-more', num(hiddenReplies)) }}<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-down gt:size-icon-sm" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
            </template>
          </button>
          <button
            v-if="canHide"
            type="button"
            class="ext-glowthread-replies-toggle gt-hit gt:inline-flex gt:items-center gt:gap-1 gt:text-meta gt:font-medium gt:text-accent-text gt:hover:underline"
            aria-expanded="true"
            :aria-controls="repliesId"
            @click="hideReplies"
          >
            {{ t('replies-hide') }}<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-up gt:size-icon-sm" aria-hidden="true"><path d="m18 15-6-6-6 6" /></svg>
          </button>
        </div>
        <p v-if="slot && slot.status === 'error'" class="ext-glowthread-error gt:flex gt:flex-wrap gt:items-center gt:gap-x-3 gt:text-meta gt:text-danger" role="alert">
          {{ errorMessage(slot.error) }}
          <button type="button" class="gt-hit gt:font-medium gt:text-accent-text gt:hover:underline" @click="showMore">{{ t('retry') }}</button>
        </p>
    </div>
  </article>
</template>
