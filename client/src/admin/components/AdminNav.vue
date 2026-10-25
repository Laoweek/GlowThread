<script setup lang="ts">
import { computed } from 'vue'
import { Inbox, Keyboard, ListFilter, ScrollText, Settings2 } from '@lucide/vue'
import { useHub } from '../context'
import type { Route } from '../router'
import { logUrl, pageUrl } from '@/lib/wiki'
import { t, num } from '@/lib/i18n'

/**
 * Queue and Settings, plus the places a moderator goes next: the comment log, the word-filter
 * page and the keyboard shortcuts. A rail on wide screens, a row above the content otherwise.
 */
defineProps<{ vertical: boolean }>()
const hub = useHub()
const counts = computed(() => hub.queue.state.counts)
const pending = computed(() => (counts.value ? counts.value.reported + counts.value.held : null))
const settingsRoute: Route = { view: 'settings' }
const filterPage = 'MediaWiki:GlowThread-filter'

function go(e: MouseEvent, r: Route) {
  if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return
  e.preventDefault()
  hub.navigate(r)
}

const item = 'gt-hit gt:flex gt:items-center gt:gap-2 gt:rounded-control gt:text-body gt:no-underline gt:transition-colors'
</script>

<template>
  <nav
    v-if="vertical"
    class="ext-glowthread-hub-nav gt:sticky gt:flex gt:flex-col gt:gap-0.5"
    :style="{ top: 'var(--gt-sticky-top)' }"
    :aria-label="t('admin-nav-label')"
  >
    <a
      :href="hub.href(hub.lastQueue.value)"
      :class="[item, 'gt:h-8 gt:px-2', hub.route.value.view === 'queue' ? 'gt:bg-surface-strong gt:font-medium gt:text-fg' : 'gt:text-fg-muted gt:hover:bg-surface-subtle gt:hover:text-fg']"
      :aria-current="hub.route.value.view === 'queue' ? 'page' : undefined"
      @click="go($event, hub.lastQueue.value)"
    >
      <Inbox class="gt:size-icon gt:shrink-0" aria-hidden="true" />
      <span class="gt:flex-1">{{ t('admin-nav-queue') }}</span>
      <span v-if="pending" class="gt-num gt:text-meta" :class="hub.route.value.view === 'queue' ? 'gt:text-fg' : 'gt:text-fg-muted'">{{ num(pending) }}</span>
    </a>
    <a
      :href="hub.href(settingsRoute)"
      :class="[item, 'gt:h-8 gt:px-2', hub.route.value.view === 'settings' ? 'gt:bg-surface-strong gt:font-medium gt:text-fg' : 'gt:text-fg-muted gt:hover:bg-surface-subtle gt:hover:text-fg']"
      :aria-current="hub.route.value.view === 'settings' ? 'page' : undefined"
      @click="go($event, settingsRoute)"
    >
      <Settings2 class="gt:size-icon gt:shrink-0" aria-hidden="true" />
      <span class="gt:flex-1">{{ t('admin-nav-settings') }}</span>
    </a>
    <div class="gt:mx-2 gt:my-3 gt:h-px gt:bg-border" role="separator" />
    <a :href="logUrl({ type: 'glowthread' })" :class="[item, 'gt:h-7 gt:px-2 gt:text-meta gt:text-fg-muted gt:hover:bg-surface-subtle gt:hover:text-fg']">
      <ScrollText class="gt:size-icon-sm gt:shrink-0" aria-hidden="true" />{{ t('admin-nav-log') }}
    </a>
    <a :href="pageUrl(filterPage)" :class="[item, 'gt:h-7 gt:px-2 gt:text-meta gt:text-fg-muted gt:hover:bg-surface-subtle gt:hover:text-fg']">
      <ListFilter class="gt:size-icon-sm gt:shrink-0" aria-hidden="true" />{{ t('admin-nav-filter') }}
    </a>
    <button
      type="button"
      :class="[item, 'gt-kbd-hint gt:h-7 gt:px-2 gt:text-meta gt:text-fg-muted gt:hover:bg-surface-subtle gt:hover:text-fg']"
      @click="hub.openShortcuts()"
    >
      <Keyboard class="gt:size-icon-sm gt:shrink-0" aria-hidden="true" />
      <span class="gt:flex-1 gt:text-left">{{ t('admin-nav-shortcuts') }}</span>
      <kbd class="gt-kbd" aria-hidden="true">?</kbd>
    </button>
  </nav>

  <!-- Above the content: the same segmented switch as Author / Page in the record panel, so it
       does not repeat the queue tabs' underline. -->
  <nav v-else class="ext-glowthread-hub-nav gt:flex gt:items-center gt:gap-1" :aria-label="t('admin-nav-label')">
    <a
      v-for="entry in [
        { route: hub.lastQueue.value, view: 'queue', label: t('admin-nav-queue') },
        { route: settingsRoute, view: 'settings', label: t('admin-nav-settings') },
      ]"
      :key="entry.view"
      :href="hub.href(entry.route)"
      class="gt-hit gt:inline-flex gt:h-8 gt:items-center gt:gap-1.5 gt:rounded-control gt:px-3 gt:text-body gt:no-underline gt:transition-colors"
      :class="hub.route.value.view === entry.view ? 'gt:bg-surface-strong gt:font-medium gt:text-fg' : 'gt:text-fg-muted gt:hover:bg-surface-subtle gt:hover:text-fg'"
      :aria-current="hub.route.value.view === entry.view ? 'page' : undefined"
      @click="go($event, entry.route)"
    >
      {{ entry.label }}
      <!-- On the selected (strong) surface the count takes the full text colour, for contrast. -->
      <span v-if="entry.view === 'queue' && pending" class="gt-num gt:text-meta gt:font-normal" :class="hub.route.value.view === 'queue' ? 'gt:text-fg' : 'gt:text-fg-muted'">{{ num(pending) }}</span>
    </a>
    <a :href="logUrl({ type: 'glowthread' })" class="gt-hit gt:ml-auto gt:inline-flex gt:h-8 gt:items-center gt:gap-1.5 gt:rounded-control gt:px-2 gt:text-meta gt:text-fg-muted gt:no-underline gt:hover:bg-surface-subtle gt:hover:text-fg">
      <ScrollText class="gt:size-icon-sm" aria-hidden="true" />{{ t('admin-nav-log') }}
    </a>
    <button
      v-if="!hub.phone.value"
      type="button"
      class="gt-hit gt-kbd-hint gt:inline-flex gt:size-8 gt:items-center gt:justify-center gt:rounded-control gt:text-fg-muted gt:hover:bg-surface-subtle gt:hover:text-fg"
      :aria-label="t('admin-nav-shortcuts')"
      @click="hub.openShortcuts()"
    >
      <Keyboard class="gt:size-icon" aria-hidden="true" />
    </button>
  </nav>
</template>
