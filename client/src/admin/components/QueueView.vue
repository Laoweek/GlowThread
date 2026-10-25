<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { CheckCheck, ChevronDown, FileText, Search, SlidersHorizontal, UserRound, X } from '@lucide/vue'
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui'
import { Button } from '@/ui/button'
import { Checkbox } from '@/ui/checkbox'
import { Spinner } from '@/ui/spinner'
import { errorMessage } from '../errors'
import { t, num } from '@/lib/i18n'
import { revealInvisible } from '@/lib/names'
import { useHub } from '../context'
import { SORTS, TABS, defaultSort, isFiltered, sortOf } from '../router'
import type { QueueRoute, Sort, Tab } from '../router'
import { frequentReporters } from '../moderation'
import { pageSuggestions, userSuggestions } from '../wikiApi'
import QueueItem from './QueueItem.vue'
import FilterField from './FilterField.vue'
import BulkBar from './BulkBar.vue'
import QueueSkeleton from './QueueSkeleton.vue'
import Notice from './Notice.vue'

/** The queue: tabs with counts, filters, the list of items, paging and the bulk bar. */
const hub = useHub()
const q = hub.queue
const route = computed(() => hub.route.value as QueueRoute)
const counts = computed(() => q.state.counts)
const items = computed(() => q.state.items)
// One record for the whole list, updated key by key: only the badges whose count changes
// re-render, not every item (with 400 items loaded, every action used to re-render all of
// them).
const frequent = reactive<Record<string, number>>({})
watch(() => frequentReporters(items.value), (next) => {
  for (const k of Object.keys(frequent)) if (!(k in next)) delete frequent[k]
  for (const k of Object.keys(next)) if (frequent[k] !== next[k]) frequent[k] = next[k]
}, { immediate: true })
// The sort <select> is drawn once the view is in the page: a <select> in the tree Vue builds
// before inserting it leaves Safari with stale styles on the hub root (its inherited design
// tokens and font are lost until the root is laid out again).
const attached = ref(false)
onMounted(() => { attached.value = true })
const busySet = computed(() => new Set(q.state.busy))
const staleSet = computed(() => new Set(q.state.stale))
const allSelected = computed(() => items.value.length > 0 && q.state.selected.length === items.value.length)

// Feed articles carry their position. Set it on the DOM after each change rather than as a
// prop, so removing one item does not re-render every item after it.
const feed = ref<HTMLElement | null>(null)
watch(() => items.value.map((x) => x.comment.id).join(','), () => nextTick(() => {
  if (!feed.value) return
  const articles = feed.value.children
  for (let i = 0; i < articles.length; i++) articles[i].setAttribute('aria-posinset', String(i + 1))
}), { immediate: true })
const filtersOpen = ref(false)
const activeFilters = computed(() => (route.value.page ? 1 : 0) + (route.value.user ? 1 : 0))

function setTab(v: string | number) {
  hub.navigate({ ...route.value, tab: v as Tab })
}

function setFilter(name: 'page' | 'user' | 'q', value: string) {
  if (route.value[name] === value) return
  hub.navigate({ ...route.value, [name]: value, subject: null })
}

function clearFilters() {
  hub.navigate({ ...route.value, page: '', user: '', q: '', reporter: '', subject: null })
}

function setSort(e: Event) {
  const value = (e.target as HTMLSelectElement).value as Sort
  hub.navigate({ ...route.value, sort: value === defaultSort(route.value.tab) ? '' : value })
}
const tabLabel = (tab: Tab) => t('admin-tab-' + tab)
const loadedLabel = computed(() => {
  const n = items.value.length
  if (q.state.next) return t('admin-loaded-more', num(n))
  return t('admin-loaded', num(n))
})
const emptyKey = computed(() => {
  const r = route.value
  if (q.state.pageMissing) return 'page-missing'
  // Everything by one author or on one page, and there is nothing: say that, not "filters".
  if (r.tab === 'all' && !r.q && r.user && !r.page) return 'user'
  if (r.tab === 'all' && !r.q && r.page && !r.user) return 'page'
  if (isFiltered(r)) return 'filtered'
  return r.tab
})
</script>

<template>
  <div class="ext-glowthread-queue gt:flex gt:flex-col">
    <h2 class="gt-sr-only">{{ t('admin-nav-queue') }}</h2>

    <Notice v-if="hub.blocked.value && hub.viewer.value && hub.viewer.value.block" tone="danger" class="gt:mb-3">
      {{ t('admin-blocked-notice', hub.viewer.value.block.reason || t('hold-unknown')) }}
    </Notice>

    <TabsRoot :model-value="route.tab" activation-mode="manual" @update:model-value="setTab">
      <TabsList class="ext-glowthread-queue-tabs gt-scroll gt:flex gt:items-center gt:gap-1 gt:overflow-x-auto gt:border-b gt:border-border" :aria-label="t('admin-tabs-label')">
        <TabsTrigger
          v-for="tab in TABS"
          :key="tab"
          :value="tab"
          class="ext-glowthread-tab gt-hit gt:relative gt:inline-flex gt:h-10 gt:shrink-0 gt:items-center gt:gap-1.5 gt:px-2.5 gt:text-body gt:whitespace-nowrap gt:text-fg-muted gt:transition-colors gt:hover:text-fg gt:data-[state=active]:font-medium gt:data-[state=active]:text-fg gt:before:absolute gt:before:right-2 gt:before:bottom-[calc(var(--gt-v-border-width)*-1)] gt:before:left-2 gt:before:h-(--gt-v-indicator-width) gt:before:rounded-full gt:before:bg-transparent gt:data-[state=active]:before:bg-accent"
        >
          {{ tabLabel(tab) }}
          <span v-if="tab !== 'all' && counts" class="gt-num gt:text-meta gt:font-normal gt:text-fg-muted">{{ num(counts[tab]) }}</span>
        </TabsTrigger>
      </TabsList>

      <TabsContent :value="route.tab" class="gt:outline-none" :tabindex="-1">
        <!-- Filters: text, page, author. On phones page and author fold behind Filters. -->
        <div class="gt:flex gt:flex-wrap gt:items-center gt:gap-2 gt:py-3">
          <FilterField
            id="gt-filter-q"
            class="gt:min-w-[12em] gt:flex-[2_1_14em]"
            :value="route.q"
            :label="t('admin-filter-q')"
            :placeholder="t('admin-filter-q-placeholder')"
            :icon="Search"
            hint="/"
            @submit="setFilter('q', $event)"
            @leave="hub.focusItem()"
          />
          <button
            v-if="hub.phone.value"
            type="button"
            class="gt-hit gt:inline-flex gt:h-8 gt:items-center gt:gap-1.5 gt:rounded-control gt:border gt:border-border-control gt:px-2.5 gt:text-body"
            :aria-expanded="filtersOpen ? 'true' : 'false'"
            aria-controls="gt-filter-more"
            @click="filtersOpen = !filtersOpen"
          >
            <SlidersHorizontal class="gt:size-icon" aria-hidden="true" />{{ t('admin-filter-more') }}
            <span v-if="activeFilters" class="gt-num gt:text-meta gt:text-accent-text">{{ num(activeFilters) }}</span>
          </button>
          <div
            v-show="!hub.phone.value || filtersOpen || activeFilters"
            id="gt-filter-more"
            class="gt:flex gt:min-w-0 gt:flex-[3_1_20em] gt:flex-wrap gt:gap-2"
          >
            <FilterField
              id="gt-filter-page"
              class="gt:min-w-[10em] gt:flex-1"
              :value="route.page"
              :label="t('admin-filter-page')"
              :placeholder="t('admin-filter-page-placeholder')"
              :icon="FileText"
              :suggest="pageSuggestions"
              @submit="setFilter('page', $event)"
              @leave="hub.focusItem()"
            />
            <FilterField
              id="gt-filter-user"
              class="gt:min-w-[10em] gt:flex-1"
              :value="route.user"
              :label="t('admin-filter-user')"
              :placeholder="t('admin-filter-user-placeholder')"
              :icon="UserRound"
              :suggest="userSuggestions"
              @submit="setFilter('user', $event)"
              @leave="hub.focusItem()"
            />
          </div>
        </div>

        <!-- Status line: select all, how much is loaded, the order, news. -->
        <div class="gt:flex gt:min-h-9 gt:flex-wrap gt:items-center gt:gap-x-3 gt:gap-y-1 gt:border-b gt:border-border gt:px-3 gt:pb-2 gt:text-meta gt:text-fg-muted">
          <label v-if="items.length" class="gt:flex gt:items-center gt:gap-2 gt:text-fg">
            <Checkbox :model-value="allSelected" :aria-label="t('admin-select-all')" @update:model-value="q.setAllSelected(!allSelected)" />
            <span aria-hidden="true">{{ t('admin-select-all') }}</span>
          </label>
          <span v-if="q.state.status === 'ready' && items.length" class="gt-num">{{ loadedLabel }}</span>
          <label v-if="attached" class="gt:inline-flex gt:items-center gt:gap-1.5">
            <span>{{ t('admin-sort-label') }}</span>
            <!-- The reset removes the native arrow: draw one, so it reads as a dropdown. -->
            <span class="gt-hit gt:relative gt:inline-flex gt:items-center">
              <select
                class="gt:relative gt:z-[1] gt:h-7 gt:cursor-pointer gt:rounded-control gt:border gt:border-border-control gt:bg-surface gt:pr-6 gt:pl-1.5 gt:text-meta gt:text-fg gt:focus:border-accent"
                :value="sortOf(route)"
                @change="setSort"
              >
                <option v-for="o in SORTS" :key="o" :value="o">{{ t('admin-sort-' + o) }}</option>
              </select>
              <ChevronDown class="gt:pointer-events-none gt:absolute gt:right-1.5 gt:z-[2] gt:size-icon-sm gt:text-fg-muted" aria-hidden="true" />
            </span>
          </label>
          <span v-if="route.reporter" class="gt:inline-flex gt:h-7 gt:items-center gt:gap-1 gt:rounded-pill gt:bg-warning-subtle gt:pr-1 gt:pl-2.5 gt:text-warning">
            {{ t('admin-reporter-chip', revealInvisible(route.reporter)) }}
            <button
              type="button"
              class="gt-hit gt:inline-flex gt:size-5 gt:items-center gt:justify-center gt:rounded-full gt:hover:bg-surface-strong"
              :aria-label="t('admin-reporter-clear')"
              @click="hub.navigate({ ...route, reporter: '', subject: null })"
            >
              <X class="gt:size-icon-xs" aria-hidden="true" />
            </button>
          </span>
          <button
            v-if="q.fresh.value"
            type="button"
            class="gt-hit gt:ml-auto gt:inline-flex gt:h-7 gt:items-center gt:rounded-pill gt:bg-accent-subtle gt:px-2.5 gt:font-medium gt:text-accent-text"
            @click="q.load()"
          >
            {{ t('admin-fresh', num(q.fresh.value)) }}
          </button>
          <button
            v-if="isFiltered(route)"
            type="button"
            class="gt-hit gt-link gt:font-medium"
            :class="q.fresh.value ? '' : 'gt:ml-auto'"
            @click="clearFilters"
          >
            {{ t('admin-filter-reset') }}
          </button>
        </div>

        <QueueSkeleton v-if="q.state.status === 'loading' && !items.length" />
        <Notice
          v-else-if="q.state.status === 'error'"
          tone="danger"
          class="gt:mt-4"
          :action="t('retry')"
          @action="q.load()"
        >
          {{ errorMessage(q.state.error) }}
        </Notice>
        <!-- A page can come back short or empty with more to load (the server skips comments on
             pages the moderator cannot read): then there is no empty state, only Load more. -->
        <div
          v-else-if="!items.length && !q.state.next"
          id="gt-queue-status"
          tabindex="-1"
          class="ext-glowthread-empty gt:flex gt:flex-col gt:items-center gt:gap-2 gt:px-4 gt:py-16 gt:text-center"
        >
          <CheckCheck class="gt:size-icon-2xl gt:text-fg-muted" aria-hidden="true" />
          <div class="gt:text-title gt:font-medium gt:text-fg">{{ t('admin-empty-' + emptyKey + '-title', route.user ? revealInvisible(route.user) : route.page) }}</div>
          <div class="gt:max-w-[30em] gt:text-body gt:text-fg-muted">{{ t('admin-empty-' + emptyKey) }}</div>
          <Button v-if="emptyKey === 'filtered' || emptyKey === 'page-missing'" size="sm" class="gt:mt-2" @click="clearFilters">{{ t('admin-filter-reset') }}</Button>
        </div>
        <div
          v-else
          ref="feed"
          role="feed"
          :aria-busy="q.state.loadingMore || q.state.status === 'loading' ? 'true' : 'false'"
          :aria-label="tabLabel(route.tab)"
          :class="q.state.status === 'loading' ? 'gt:opacity-60' : ''"
        >
          <QueueItem
            v-for="item in items"
            :key="item.comment.id"
            v-memo="[item, item.comment.id === q.state.focusId, busySet.has(item.comment.id), staleSet.has(item.comment.id), route.tab]"
            :item="item"
            :focused="item.comment.id === q.state.focusId"
            :busy="busySet.has(item.comment.id)"
            :stale="staleSet.has(item.comment.id)"
            :frequent="frequent"
            :tab="route.tab"
          />
        </div>

        <div v-if="(items.length || q.state.status === 'ready') && (q.state.next || q.state.moreError)" class="gt:flex gt:flex-col gt:items-center gt:gap-2 gt:py-4">
          <div v-if="q.state.moreError" class="ext-glowthread-error gt:text-meta gt:text-danger" role="alert">{{ errorMessage(q.state.moreError) }}</div>
          <Button class="ext-glowthread-load-more gt:min-w-40" :disabled="q.state.loadingMore" @click="q.loadMore()">
            <Spinner v-if="q.state.loadingMore" />{{ q.state.loadingMore ? t('loading') : t('load-more') }}
          </Button>
        </div>
      </TabsContent>
    </TabsRoot>

    <BulkBar v-if="q.state.selected.length" />
  </div>
</template>
