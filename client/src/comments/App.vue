<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, provide, ref, shallowRef, watch } from 'vue'
import { ConfigProvider } from 'reka-ui'
import type { Comment, PageConfig, Sort } from '@/shared/types'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/ui/tabs'
import { Toaster } from '@/ui/toast'
import { Skeleton } from '@/ui/skeleton'
import { createStore } from './store'
import { sweepDrafts } from './drafts'
import { FOCUS_HEADING, OVERLAYS, REVEAL, STORE } from './context'
import ComposerGate from './components/ComposerGate.vue'
import CommentFeed from './components/CommentFeed.vue'
import LinkedThread from './components/LinkedThread.vue'
import { BulkBar, Composer, ModQueue, ModTools, ReportDialog, extras, menu, tellFailed } from './lazy'
import ErrorState from './components/ErrorState.vue'
import LoadingState from './components/LoadingState.vue'
import { actionPost, restGet } from '@/lib/api'
import { errorMessage } from '@/lib/errors'
import { PHONE_QUERY, pageDir, useMediaQuery } from '@/lib/media'
import { t, num } from '@/lib/i18n'
import { prepareDateFormats } from '@/lib/time'

const props = defineProps<{ portal: HTMLElement, reserve?: number }>()
const config = mw.config.get('wgGlowThread') as PageConfig
const store = createStore({ config, get: restGet, post: actionPost })
provide(STORE, store)
// The unsent drafts of anyone else who used this browser on this wiki go.
sweepDrafts(String(mw.config.get('wgWikiID') || ''), mw.config.get('wgUserName') as string | null)

// One clock for every relative time in the section.
const now = ref(Date.now())
const clock = window.setInterval(() => {
  now.value = Date.now()
}, 60000)
provide('gt-now', now)

const phone = useMediaQuery(PHONE_QUERY)
const reportTarget = shallowRef<Comment | null>(null)
const reportAnchor = shallowRef<HTMLElement | null>(null)
// The report form is in the extras module, mounted closed once that is here. Opened while the
// ⋯ menu hands focus back, the popover would take that for a move away and close: when the form
// is still loading at the first report, it opens once focus is back on ⋯ (soon, or not at all
// when the reader went elsewhere).
const reportUsed = ref(false)
provide(OVERLAYS, {
  phone,
  openReport(c: Comment, anchor: HTMLElement) {
    const show = () => {
      reportAnchor.value = anchor
      reportTarget.value = c
    }
    if (extras.module.value) return show()
    if (extras.failed.value) return tellFailed()
    reportUsed.value = true
    extras.load().then(() => nextTick()).then(() => {
      if (!extras.module.value) return
      const closing = props.portal.querySelector('[role="menu"][aria-labelledby="' + anchor.id + '"]')
      if (document.activeElement === anchor || !closing) return show()
      anchor.addEventListener('focus', show, { once: true })
      window.setTimeout(() => anchor.removeEventListener('focus', show), 1000)
    })
  },
})

const loggedIn = !mw.user.isAnon()
const viewer = store.viewer
// The composer and the moderation tools are in the extras module (lazy.ts). For a reader who is
// logged in or can post, the list waits for it, so the section is drawn once and nothing moves:
// at most EXTRAS_WAIT ms once the list is here, then the parts follow when they come. Once the
// list is drawn it never goes back to the placeholder.
const EXTRAS_WAIT = 5000
const drawn = ref(false)
const waiting = computed(() => !drawn.value && !extras.module.value && !extras.failed.value && !!viewer.value &&
  (viewer.value.loggedIn || viewer.value.canPost))
const data = computed(() => (waiting.value ? null : store.data.value))
let waitTimer = 0
watch(waiting, (w) => {
  window.clearTimeout(waitTimer)
  if (!w) return
  extras.load()
  waitTimer = window.setTimeout(() => {
    drawn.value = true
  }, EXTRAS_WAIT)
}, { immediate: true })
watch(data, (d) => {
  if (d) drawn.value = true
})
// Logged-in readers start at once, alongside the list (the loader asked for it already).
if (loggedIn) extras.load()
const first = computed(() => store.state.lists[store.state.sort])
const closedForReader = computed(() => !!data.value && data.value.page.state === 'disabled' && !store.canModerate.value)
// Until the list is drawn the section takes exactly the space the loader held, neither more
// (the loading placeholder is taller than a closed page's note) nor less.
// Skeleton rows enough to fill that space (about 90 px each, after the header and composer).
const loadingRows = Math.max(2, Math.min(30, Math.floor(((props.reserve || 280) - 120) / 90)))
const loadingStyle = computed(() => (!data.value && first.value.status !== 'error'
  ? { height: (props.reserve || 280) + 'px', overflow: 'hidden' }
  : undefined))
const focusId = ref(0)

// ---- permalinks: #gt-<id> scrolls to the comment and highlights it. A comment that is not
// in the list shows its conversation in place: unfolded in the list when its thread is
// there, otherwise above the list (LinkedThread).

function targetFromHash(): number {
  const m = /^#gt-(\d+)$/.exec(location.hash)
  return m ? Number(m[1]) : 0
}

// The app's own root. Page content can contain elements with the same IDs (id="glowthread",
// id="gt-12" in wikitext), so lookups stay inside the app.
const rootRef = ref<{ $el: HTMLElement } | null>(null)
const appRoot = (): HTMLElement | null => (rootRef.value ? rootRef.value.$el : null)
// The section heading, found inside the app: page text can carry the same ID. A deleted
// comment hands focus to it.
provide(FOCUS_HEADING, () => {
  const root = appRoot()
  const h = root && root.querySelector<HTMLElement>('#gt-heading')
  if (h) h.focus()
})

function goToHash() {
  const root = appRoot()
  // #glowthread, or #flowthread in links made before a wiki moved from FlowThread.
  if (location.hash === '#glowthread' || location.hash === '#flowthread') {
    const section = root && root.closest('.ext-glowthread')
    if (section) section.scrollIntoView()
    return
  }
  const id = targetFromHash()
  if (id) reveal(id)
}

/** Shows comment `id` in place (see above), scrolls to it and highlights it. */
async function reveal(id: number) {
  focusId.value = id
  const where = await store.showComment(id)
  await nextTick()
  const root = appRoot()
  if (!root) return
  // The list's copy when there is one, else the linked conversation (or its note or error).
  const el = where === 'list' || where === 'linked'
    ? root.querySelector<HTMLElement>('[data-comment="' + id + '"]')
    : root.querySelector<HTMLElement>('[data-linked]')
  if (!el) return
  el.scrollIntoView({ block: 'center' })
  // Keyboard and screen reader users continue from the linked comment.
  el.setAttribute('tabindex', '-1')
  el.focus({ preventScroll: true })
}
provide(REVEAL, reveal)

async function onSort(v: string | number) {
  store.state.modFilter = null
  await store.setSort(v as Sort)
}

async function retry() {
  try {
    await store.load()
  } catch {
    // the error state stays
  }
}

onMounted(async () => {
  window.addEventListener('hashchange', goToHash)
  try {
    const loading = store.load()
    // While the list is on its way: the date formats, in a task of their own.
    window.setTimeout(prepareDateFormats, 0)
    await loading
  } catch {
    return
  }
  if (!data.value) {
    await new Promise<void>((resolve) => {
      const stop = watch(data, (d) => {
        if (d) {
          stop()
          resolve()
        }
      })
    })
  }
  goToHash()
})
onBeforeUnmount(() => {
  window.clearInterval(clock)
  window.clearTimeout(waitTimer)
  window.removeEventListener('hashchange', goToHash)
})
</script>

<template>
  <ConfigProvider :teleport-to="portal" :dir="pageDir()">
    <Tabs
      ref="rootRef"
      as="section"
      class="ext-glowthread-section glowthread gt:mt-8 gt:flex gt:flex-col gt:gap-4 gt:bg-bg"
      :style="loadingStyle"
      :model-value="store.state.sort"
      :aria-label="t('title')"
      :unmount-on-hide="true"
      @update:model-value="onSort"
      @touchstart.passive.once="menu.load()"
    >
      <header class="ext-glowthread-header gt:flex gt:min-h-10 gt:items-center gt:gap-3 gt:border-b gt:border-border gt:pb-2">
        <h2 id="gt-heading" tabindex="-1" class="ext-glowthread-heading gt:flex gt:items-baseline gt:gap-2 gt:text-title gt:font-semibold gt:text-fg">
          {{ t('title') }}
          <span v-if="data && !closedForReader" class="ext-glowthread-count gt-num gt:text-body gt:font-normal gt:text-fg-muted">{{ num(data.count) }}</span>
        </h2>
        <TabsList v-if="!closedForReader" class="ext-glowthread-sort gt:ml-auto" :aria-label="t('sort-label')">
          <TabsTrigger value="hot">{{ t('sort-hot') }}</TabsTrigger>
          <span aria-hidden="true" class="gt:text-fg-subtle">·</span>
          <TabsTrigger value="new">{{ t('sort-new') }}</TabsTrigger>
        </TabsList>
      </header>

      <p v-if="closedForReader" class="ext-glowthread-notice gt:rounded-surface gt:bg-surface-subtle gt:px-4 gt:py-3 gt:text-body gt:text-fg-muted" role="note" data-glowthread-tone="neutral">
        {{ t('gate-disabled') }}
      </p>

      <template v-else>
        <ModTools v-if="store.canModerate.value" />
        <p v-if="data && data.page.state === 'disabled'" class="ext-glowthread-notice gt:rounded-control gt:bg-warning-subtle gt:px-3 gt:py-2 gt:text-meta gt:text-warning" role="note" data-glowthread-tone="warning">
          {{ t('mod-disabled-note') }}
        </p>

        <!-- Composer, or why the reader cannot post. -->
        <div v-if="!data && first.status !== 'error'" class="ext-glowthread-loading gt:flex gt:items-center gt:gap-(--gt-v-avatar-gap)" aria-hidden="true">
          <Skeleton class="gt:size-9 gt:shrink-0 gt:rounded-avatar" />
          <Skeleton class="gt:h-10 gt:flex-1 gt:rounded-surface" />
        </div>
        <template v-else-if="viewer">
          <Composer v-if="viewer.canPost || viewer.postBlockedReason === 'ratelimited'" :portal="portal" />
          <ComposerGate v-else :reason="viewer.postBlockedReason || (loggedIn ? 'rights' : 'login')" />
        </template>

        <ModQueue v-if="store.state.modFilter" :portal="portal" />
        <template v-else>
          <ErrorState
            v-if="first.status === 'error' && !data"
            :message="errorMessage(first.error)"
            @retry="retry"
          />
          <LoadingState v-else-if="!data" :rows="loadingRows" />
          <template v-else>
            <LinkedThread :portal="portal" :focus="focusId" />
            <TabsContent value="hot" class="gt:outline-none" :tabindex="-1">
              <CommentFeed sort="hot" :portal="portal" :focus="focusId" />
            </TabsContent>
            <TabsContent value="new" class="gt:outline-none" :tabindex="-1">
              <CommentFeed sort="new" :portal="portal" :focus="focusId" />
            </TabsContent>
          </template>
        </template>

        <BulkBar v-if="store.state.manage" />
      </template>
    </Tabs>

    <ReportDialog v-if="reportUsed || extras.module.value" :comment="reportTarget" :anchor="reportAnchor" :phone="phone" @close="reportTarget = null" />
    <Toaster :to="portal" :close-label="t('close')" />
  </ConfigProvider>
</template>
