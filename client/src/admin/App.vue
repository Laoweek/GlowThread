<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, provide, ref, shallowRef, watch } from 'vue'
import { ConfigProvider } from 'reka-ui'
import type { AdminConfig, QueueItem } from '@/shared/types'
import { actionPost, restGet } from '@/lib/api'
import { errorMessage } from './errors'
import { sentences, t } from '@/lib/i18n'
import { pageDir } from '@/lib/media'
import { Toaster, toast } from '@/ui/toast'
import { Sheet, SheetContent } from '@/ui/sheet'
import { createQueue } from './queue'
import { createHistory } from './history'
import type { Subject } from './history'
import { createSettings } from './settings'
import { formatRoute, parseRoute, queueRoute, routeTitle, routeUrl, sameRoute, specialBase } from './router'
import type { Route } from './router'
import { actionTargets, commandFor, inRecord, mayAct } from './keyboard'
import { revealInvisible } from '@/lib/names'
import type { Kind, ModAction } from './moderation'
import { HUB } from './context'
import type { Hub } from './context'
import * as wiki from './wikiApi'
import type { ViewerInfo } from './wikiApi'
import AdminNav from './components/AdminNav.vue'
import QueueView from './components/QueueView.vue'
import SettingsView from './components/SettingsView.vue'
import RecordPanel from './components/RecordPanel.vue'
import ShortcutSheet from './components/ShortcutSheet.vue'
import EraseDialog from './components/EraseDialog.vue'

/**
 * Special:GlowThread: the moderation hub. A rail (Queue, Settings), the queue or the settings,
 * and the record of the focused author or page beside the queue. The app owns the
 * subpages: it routes them, keeps browser history and the document title, and runs the
 * keyboard model. Every action goes through the Action API, which checks rights and
 * blocks again and logs it.
 */
const props = defineProps<{ portal: HTMLElement }>()
const config = mw.config.get('wgGlowThreadAdmin') as AdminConfig
const base = specialBase(String(mw.config.get('wgPageName') || 'Special:GlowThread'))

// ---- state -------------------------------------------------------------------------

const exactUndo = ref(false)
const queue = createQueue({
  restBase: config.restBase,
  get: restGet,
  post: actionPost,
  resolvePage: wiki.resolvePage,
  exactUndo: () => exactUndo.value,
})
const history = createHistory({ restBase: config.restBase, get: restGet, post: actionPost })
const settings = createSettings({
  restBase: config.restBase,
  get: restGet,
  post: actionPost,
  filterPage: wiki.filterPage,
  latestLogId: wiki.latestSettingsLogId,
})
const route = ref<Route>(parseRoute(config.subPage, location.search))
const viewer = ref<ViewerInfo | null>(null)
const blocked = computed(() => !!(viewer.value && viewer.value.block))

const now = ref(Date.now())
const clock = window.setInterval(() => {
  now.value = Date.now()
}, 60000)
provide('gt-now', now)

// ---- layout: measured on the app's own width (skins give the content different widths) ----

const root = ref<HTMLElement | null>(null)
const width = ref(0)
const layout = computed(() => (width.value >= 1080 ? 'wide' : width.value >= 720 ? 'medium' : 'narrow'))
const wide = computed(() => layout.value !== 'narrow')
const phone = computed(() => width.value > 0 && width.value < 640)
let observer: ResizeObserver | null = null
// Vector 2022 shows a sticky header once the page scrolls: sticky parts sit below it.
const stickyTop = document.body.classList.contains('skin-vector-2022') ? 64 : 16

// ---- routing -----------------------------------------------------------------------

function applyTitle() {
  document.title = t('admin-doc-title', routeTitle(route.value)) + ' - ' + mw.config.get('wgSiteName')
}

function apply() {
  const r = route.value
  applyTitle()
  if (r.view === 'queue') {
    queue.show(r.tab, { page: r.page, user: r.user, q: r.q, reporter: r.reporter, sort: r.sort })
    if (r.subject === 'user' && r.user) showRecord({ type: 'user', name: r.user })
    if (r.subject === 'page' && r.page) {
      wiki.resolvePage(r.page).then((id) => {
        if (id && route.value === r) showRecord({ type: 'page', id, title: r.page })
      }, () => undefined)
    }
  } else if (settings.state.status === 'idle' || settings.state.status === 'error') {
    settings.load()
  }
}

const lastQueue = ref<Route>(route.value.view === 'queue' ? route.value : queueRoute())

// Parameters of the address that are about the reader, not the view: they stay.
const kept: Record<string, string> = {}
new URLSearchParams(location.search).forEach((v, k) => {
  if (['useskin', 'uselang', 'debug', 'safemode'].indexOf(k) >= 0) kept[k] = v
})

function href(r: Route): string {
  return routeUrl(base, r, (title) => mw.util.getUrl(title), kept)
}

// The rail's Queue returns to the queue as it was last shown, whichever way it was reached.
watch(route, (r) => {
  if (r.view === 'queue') lastQueue.value = r
})

function navigate(next: Route, { replace = false } = {}) {
  if (sameRoute(next, route.value) && !replace) return
  const url = href(next)
  const state = { glowthread: formatRoute(next) }
  if (replace) window.history.replaceState(state, '', url)
  else window.history.pushState(state, '', url)
  route.value = next
  apply()
}

// A fragment link (the skin's "jump to content") adds an entry without our state: give it the
// current route, so Back and Forward through it keep the view and the address together.
function onHashChange() {
  if (!(window.history.state && window.history.state.glowthread)) {
    window.history.replaceState({ glowthread: formatRoute(route.value) }, '', location.href)
  }
}

function onPopState() {
  // Every entry this app made carries its route (the first one too, see onMounted). An entry
  // without one comes from a fragment link in the page (the skin's "jump to content"): the
  // view stays as it is.
  const st = window.history.state && window.history.state.glowthread
  if (!st) return
  route.value = parseRoute(st.sub, '?' + new URLSearchParams(st.query).toString())
  apply()
}

// ---- the record panel ----------------------------------------------------------------

const recordMode = ref<'user' | 'page'>('user')
const sheetOpen = ref(false)
// The item whose record was opened by a click, so following focus does not replace it.
let explicitFor: number | null = null

function subjectOf(item: QueueItem, mode: 'user' | 'page'): Subject | null {
  if (mode === 'page') return { type: 'page', id: item.page.id, title: item.page.title || '' }
  const a = item.comment.author
  return a && a.name && !a.hidden ? { type: 'user', name: a.name } : null
}

function showRecord(subject: Subject, item: QueueItem | null = null) {
  // A click inside another item moved focus there first: do not let that follow replace it.
  window.clearTimeout(followTimer)
  recordMode.value = subject.type
  if (item) {
    explicitFor = item.comment.id
    queue.focus(item.comment.id)
  }
  history.show(subject)
  if (!wide.value) sheetOpen.value = true
}

let followTimer = 0
watch(() => queue.focused.value && queue.focused.value.comment.id, (id) => {
  window.clearTimeout(followTimer)
  if (!id || !wide.value || route.value.view !== 'queue') return
  if (id === explicitFor) return
  explicitFor = null
  followTimer = window.setTimeout(() => {
    const item = queue.focused.value
    const s = item && subjectOf(item, recordMode.value)
    if (s) history.show(s)
  }, 150)
})

// ---- actions -----------------------------------------------------------------------

const eraseTarget = shallowRef<QueueItem | null>(null)
const shortcutsOpen = ref(false)

function focusItem(id: number | null = null) {
  nextTick(() => {
    const target = id !== null ? id : queue.state.focusId
    const el = target !== null ? document.getElementById('gt-q-' + target) : null
    if (el) {
      el.focus({ preventScroll: true })
      el.scrollIntoView({ block: 'nearest' })
    } else {
      const empty = document.getElementById('gt-queue-status')
      if (empty) empty.focus({ preventScroll: true })
    }
  })
}

function doneMessage(kind: Kind, done: Array<{ action: ModAction, status?: string | null }>): string {
  const n = done.length
  if (kind === 'pass') return t('admin-done-pass', n)
  // Say what a restore did, from the server's answer (public, or still held).
  if (kind === 'restore' && done.some((d) => d.status === 'held')) return t('mod-done-restore-held', n)
  return t('admin-done-' + (done[0] ? done[0].action : kind), n)
}

async function act(kind: Kind, ids: number[], { reason }: { reason?: string } = {}) {
  if (blocked.value) {
    toast(t('admin-blocked-action'), { tone: 'error' })
    return
  }
  const sel = queue.state.selected
  const fromSelection = sel.length > 0 && ids.length === sel.length && ids.every((id) => sel.indexOf(id) >= 0)
  const hadFocus = !!(document.activeElement && root.value && root.value.contains(document.activeElement))
  // The comments leave the list before the server answers: focus follows at once.
  const pending = queue.act(kind, ids, { reason })
  if (hadFocus) focusItem()
  const r = await pending
  if (r.pending) return
  if (r.skipped) {
    toast(t('admin-skip-' + kind))
    return
  }
  // Refused comments came back: keep focus on a real item.
  if (hadFocus && (r.error || r.stale.length)) focusItem()
  if (r.error && !r.done.length) {
    toast(errorMessage(r.error), { tone: 'error' })
    return
  }
  history.invalidate()
  if (fromSelection && r.done.length) queue.state.selected = []
  const message = sentences(
    r.done.length ? doneMessage(kind, r.done) : t('admin-nochange'),
    r.stale.length ? t('admin-stale', r.stale.length) : '',
    r.refused.length ? (r.error ? t('admin-refused-error', r.refused.length, errorMessage(r.error)) : t('admin-refused', r.refused.length)) : '',
    r.done.length && !r.undo && kind !== 'erase' ? t('admin-no-undo') : '',
  )
  toast(message, {
    tone: !r.done.length && (r.stale.length || r.refused.length) ? 'error' : 'neutral',
    action: r.undo
      ? {
          label: t('undo'),
          onClick: async () => {
            const u = await queue.undo(r)
            history.invalidate()
            if (u.error && !u.done.length) {
              toast(errorMessage(u.error), { tone: 'error' })
              return
            }
            // A restored comment whose reports stay closed no longer belongs in Reported: say so.
            const back = u.done.filter((d) => queue.state.items.some((x) => x.comment.id === d.id)).length
            const note = sentences(
              u.done.length ? t('admin-undone', u.done.length) : '',
              back < u.done.length ? t('admin-undone-elsewhere') : '',
              u.stale.length ? t('admin-stale', u.stale.length) : '',
              u.refused.length ? t('admin-refused', u.refused.length) : '',
            )
            toast(note, { tone: u.done.length ? 'neutral' : 'error' })
          },
        }
      : undefined,
  })
}

// ---- keyboard ----------------------------------------------------------------------

/** The queue item that contains keyboard focus, if any. */
function itemInFocus(): number | null {
  const active = document.activeElement as HTMLElement | null
  const article = active && active.closest ? active.closest<HTMLElement>('article[id^="gt-q-"]') : null
  return article ? Number(article.id.slice(5)) : null
}

function targets(): number[] {
  return actionTargets(itemInFocus(), queue.state.selected, queue.state.focusId)
}

// The last click was in the record panel (see mayAct()).
let clickedInRecord = false
function onPointerDown(e: PointerEvent) {
  clickedInRecord = inRecord(e.target)
}

/** Keys count only while focus is in the hub (or nowhere): not in the skin, not in a toast. */
function keysForHub(): boolean {
  const active = document.activeElement
  return !active || active === document.body || !!(root.value && root.value.contains(active))
}

function onKey(e: KeyboardEvent) {
  const cmd = commandFor(e)
  if (!cmd || !keysForHub()) return
  if (cmd === 'help') {
    e.preventDefault()
    shortcutsOpen.value = true
    return
  }
  if (route.value.view !== 'queue') return
  switch (cmd) {
    case 'next':
    case 'prev': {
      e.preventDefault()
      // The first j only puts focus on the current item; then j and k move from the item
      // the moderator is in.
      const inItem = itemInFocus()
      const onItem = !!(document.activeElement && /^gt-q-\d+$/.test(document.activeElement.id))
      if (inItem !== null) queue.focus(inItem)
      if (!onItem && inItem === null && queue.state.focusId !== null) focusItem()
      else queue.move(cmd === 'next' ? 1 : -1).then(() => focusItem())
      return
    }
    case 'toggle': {
      if (!mayAct(document.activeElement, clickedInRecord)) return
      e.preventDefault()
      const id = itemInFocus()
      const target = id !== null ? id : queue.state.focusId
      if (target !== null) queue.toggleSelected(target)
      return
    }
    case 'pass':
    case 'delete':
    case 'restore': {
      if (!mayAct(document.activeElement, clickedInRecord)) return
      const ids = targets()
      if (!ids.length) return
      e.preventDefault()
      act(cmd, ids)
      return
    }
    case 'search': {
      const input = document.getElementById('gt-filter-q')
      if (input) {
        e.preventDefault()
        input.focus()
      }
      return
    }
    case 'escape':
      if (queue.state.selected.length) {
        queue.state.selected = []
        e.preventDefault()
      }
  }
}

// ---- wiring --------------------------------------------------------------------------

const hub: Hub = {
  config,
  route,
  queue,
  history,
  settings,
  viewer,
  blocked,
  phone,
  wide,
  navigate,
  href,
  lastQueue,
  act,
  showRecord,
  recordMode,
  confirmErase(item) {
    eraseTarget.value = item
  },
  openShortcuts() {
    shortcutsOpen.value = true
  },
  focusItem,
}
provide(HUB, hub)

// Toasts move up while the bulk bar is shown, so they never cover it.
watch(() => queue.state.selected.length > 0 && route.value.view === 'queue', (raised) => {
  props.portal.classList.toggle('gt-toasts-raised', raised)
})

// Counts stay live while the hub is open and visible.
const poll = window.setInterval(() => {
  if (document.visibilityState === 'visible' && route.value.view === 'queue') queue.refreshCounts()
}, 60000)

onMounted(() => {
  if (root.value) {
    width.value = root.value.getBoundingClientRect().width
    if ('ResizeObserver' in window) {
      observer = new ResizeObserver((entries) => {
        width.value = entries[0].contentRect.width
      })
      observer.observe(root.value)
    }
  }
  window.history.replaceState({ glowthread: formatRoute(route.value) }, '', location.href)
  window.addEventListener('popstate', onPopState)
  window.addEventListener('hashchange', onHashChange)
  document.addEventListener('keydown', onKey)
  document.addEventListener('pointerdown', onPointerDown, true)
  apply()
  wiki.viewerInfo().then((v) => {
    viewer.value = v
  }, () => undefined)
  wiki.hasExactUndo().then((yes) => {
    exactUndo.value = yes
  }, () => undefined)
})
onBeforeUnmount(() => {
  window.clearTimeout(followTimer)
  window.clearInterval(clock)
  window.clearInterval(poll)
  window.removeEventListener('popstate', onPopState)
  window.removeEventListener('hashchange', onHashChange)
  document.removeEventListener('keydown', onKey)
  document.removeEventListener('pointerdown', onPointerDown, true)
  if (observer) observer.disconnect()
})

const recordTitle = computed(() => {
  const s = history.state.subject
  if (!s) return t('admin-record')
  return s.type === 'user' ? t('admin-record-user', revealInvisible(s.name)) : t('admin-record-page', s.title)
})
</script>

<template>
  <ConfigProvider :teleport-to="portal" :dir="pageDir()">
    <div
      ref="root"
      class="ext-glowthread-hub gt-admin gt:bg-bg gt:text-body gt:text-fg"
      :class="layout === 'wide' ? 'gt:grid gt:grid-cols-[136px_minmax(0,1fr)_300px] gt:items-start gt:gap-6' : layout === 'medium' ? 'gt:grid gt:grid-cols-[minmax(0,1fr)_280px] gt:items-start gt:gap-x-5 gt:gap-y-4' : 'gt:flex gt:flex-col gt:gap-4'"
      :style="{ '--gt-sticky-top': stickyTop + 'px' }"
    >
      <AdminNav :vertical="layout === 'wide'" :class="layout === 'medium' ? 'gt:col-span-2' : ''" />

      <div class="gt:min-w-0">
        <QueueView v-if="route.view === 'queue'" />
        <SettingsView v-else />
      </div>

      <aside
        v-if="wide && route.view === 'queue'"
        data-record
        class="gt-scroll gt:sticky gt:max-h-[calc(100vh-var(--gt-sticky-top)-16px)] gt:rounded-surface gt:border gt:border-border"
        :style="{ top: 'var(--gt-sticky-top)' }"
        :aria-label="recordTitle"
      >
        <RecordPanel />
      </aside>
    </div>

    <Sheet v-if="!wide" v-model:open="sheetOpen">
      <SheetContent :title="recordTitle" :close-label="t('close')">
        <RecordPanel in-sheet />
      </SheetContent>
    </Sheet>
    <ShortcutSheet v-model:open="shortcutsOpen" />
    <EraseDialog :item="eraseTarget" @close="eraseTarget = null" />
    <Toaster :to="portal" :close-label="t('close')" />
  </ConfigProvider>
</template>
