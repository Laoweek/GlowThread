<script setup lang="ts">
import { computed, ref } from 'vue'
import { ArrowRight, ExternalLink } from '@lucide/vue'
import type { PageHistoryResponse, PageState } from '@/shared/types'
import { Button } from '@/ui/button'
import { Spinner } from '@/ui/spinner'
import { toast } from '@/ui/toast'
import RelativeTime from '@/shared/RelativeTime.vue'
import { errorMessage } from '../errors'
import { logUrl, pageUrl } from '@/lib/wiki'
import { revealInvisible } from '@/lib/names'
import { t, num } from '@/lib/i18n'
import { useHub } from '../context'
import { queueRoute } from '../router'
import { isImeEnter } from '../keyboard'
import LogList from './LogList.vue'

/**
 * A page's comment state: Open, Locked or Closed with the switch (confirmed inline, with an
 * optional reason for the log), the comments by status, and recent moderation there.
 */
const props = defineProps<{ data: PageHistoryResponse }>()
const hub = useHub()
const page = computed(() => props.data.page)
const title = computed(() => page.value.title || '')
const STATES: PageState[] = ['open', 'locked', 'disabled']
const pending = ref<PageState | null>(null)
const reason = ref('')
const busy = ref(false)
const headId = computed(() => 'gt-rec-page-' + page.value.id)

function choose(s: PageState) {
  pending.value = s === page.value.state ? null : s
  reason.value = ''
}

function onEnter(e: KeyboardEvent) {
  if (isImeEnter(e)) return
  e.preventDefault()
  apply()
}

async function apply() {
  if (!pending.value || busy.value) return
  busy.value = true
  try {
    const r = await hub.history.setPageState(pending.value, reason.value.trim())
    toast(t('page-state-done-' + r.state))
    pending.value = null
  } catch (e) {
    toast(errorMessage(e), { tone: 'error' })
  } finally {
    busy.value = false
  }
}

function allComments(e: MouseEvent) {
  if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return
  e.preventDefault()
  hub.navigate(queueRoute({ tab: 'all', page: title.value, subject: 'page' }))
}
</script>

<template>
  <section class="gt:flex gt:flex-col gt:gap-4" :aria-labelledby="headId">
    <header>
      <h3 :id="headId" class="gt:truncate gt:text-title gt:font-semibold" dir="auto">
        <a v-if="page.exists && title" :href="pageUrl(title)" class="gt:text-fg gt:hover:underline">{{ title }}</a>
        <span v-else class="gt:text-fg-muted">{{ t('admin-page-gone') }}</span>
      </h3>
      <div class="gt-num gt:text-meta gt:text-fg-muted">{{ t('admin-page-floors', num(page.floors)) }}</div>
    </header>

    <div v-if="page.exists">
      <div :id="headId + '-state'" class="gt:mb-1.5 gt:text-meta gt:font-semibold gt:text-fg">{{ t('page-state-menu') }}</div>
      <div class="gt:flex gt:rounded-control gt:border gt:border-border-control gt:p-0.5" role="group" :aria-labelledby="headId + '-state'">
        <button
          v-for="s in STATES"
          :key="s"
          type="button"
          class="gt-hit gt:h-7 gt:flex-1 gt:rounded-mark gt:text-meta gt:transition-colors"
          :class="(pending || page.state) === s ? 'gt:bg-surface-strong gt:font-medium gt:text-fg' : 'gt:text-fg-muted gt:hover:text-fg'"
          :aria-pressed="page.state === s ? 'true' : 'false'"
          :disabled="hub.blocked.value || busy"
          @click="choose(s)"
        >
          {{ t('page-state-' + s) }}
        </button>
      </div>
      <div class="gt:mt-1.5 gt:text-meta gt:text-fg-muted">{{ t('page-state-' + (pending || page.state) + '-help') }}</div>
      <div v-if="page.stateChangedBy && page.stateChangedAt && !pending" class="gt:mt-0.5 gt:text-meta gt:text-fg-muted">
        {{ t('admin-page-state-by', page.stateChangedBy.name ? revealInvisible(page.stateChangedBy.name) : t('user-hidden')) }} · <RelativeTime :iso="page.stateChangedAt" />
      </div>
      <div v-if="pending" class="gt:mt-2 gt:flex gt:flex-col gt:gap-2 gt:rounded-control gt:bg-warning-subtle gt:p-2.5" role="group" :aria-label="t('page-state-confirm-' + pending)">
        <div class="gt:text-meta gt:text-warning">{{ t('page-state-confirm-' + pending) }}</div>
        <input
          v-model="reason"
          type="text"
          class="gt:h-8 gt:w-full gt:rounded-control gt:border gt:border-border-control gt:bg-surface gt:px-2.5 gt:text-body gt:focus:border-accent"
          :aria-label="t('admin-reason')"
          :placeholder="t('admin-reason-optional')"
          maxlength="500"
          @keydown.enter="onEnter"
        >
        <div class="gt:flex gt:justify-end gt:gap-2">
          <Button size="sm" variant="ghost" @click="pending = null">{{ t('cancel') }}</Button>
          <Button size="sm" :disabled="busy" @click="apply"><Spinner v-if="busy" />{{ t('page-state-apply-' + pending) }}</Button>
        </div>
      </div>
    </div>

    <dl class="gt:grid gt:grid-cols-[auto_minmax(0,1fr)] gt:gap-x-3 gt:gap-y-2 gt:text-meta">
      <dt class="gt:text-fg-muted">{{ t('admin-user-comments') }}</dt>
      <dd class="gt-num gt:flex gt:flex-wrap gt:gap-x-2.5 gt:text-fg">
        <span class="gt:whitespace-nowrap">{{ t('admin-count-visible', num(data.counts.visible)) }}</span>
        <span class="gt:whitespace-nowrap">{{ t('admin-count-held', num(data.counts.held)) }}</span>
        <span class="gt:whitespace-nowrap">{{ t('admin-count-deleted', num(data.counts.deleted)) }}</span>
        <span class="gt:whitespace-nowrap">{{ t('admin-count-erased', num(data.counts.erased)) }}</span>
      </dd>
      <dt class="gt:text-fg-muted">{{ t('chip-reported') }}</dt>
      <dd class="gt-num gt:text-fg">{{ t('admin-count-reported', num(data.counts.reported)) }}</dd>
    </dl>

    <div>
      <h4 class="gt:mb-1.5 gt:text-meta gt:font-semibold gt:text-fg">{{ t('admin-page-moderation') }}</h4>
      <LogList :entries="data.moderation || []" :empty="t('admin-log-none-page')" />
    </div>

    <div class="gt:flex gt:flex-col gt:gap-1.5 gt:text-meta">
      <a
        :href="hub.href(queueRoute({ tab: 'all', page: title, subject: 'page' }))"
        class="gt:inline-flex gt:items-center gt:gap-1 gt:font-medium gt-link"
        @click="allComments"
      >
        {{ t('admin-page-all') }}<ArrowRight class="gt-flip-rtl gt:size-icon-sm" aria-hidden="true" />
      </a>
      <a v-if="title" :href="logUrl({ type: 'glowthread', page: title })" class="gt:inline-flex gt:items-center gt:gap-1 gt-link">
        {{ t('admin-page-log') }}<ExternalLink class="gt:size-icon-xs" aria-hidden="true" />
      </a>
    </div>
  </section>
</template>
