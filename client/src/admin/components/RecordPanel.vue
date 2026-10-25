<script setup lang="ts">
import { computed } from 'vue'
import { FileText, UserRound } from '@lucide/vue'
import { Skeleton } from '@/ui/skeleton'
import { errorMessage } from '../errors'
import { t } from '@/lib/i18n'
import { useHub } from '../context'
import UserRecord from './UserRecord.vue'
import PageRecord from './PageRecord.vue'
import Notice from './Notice.vue'

/**
 * The record beside the queue (a sheet on narrow screens): the focused comment's author or
 * its page. Author and Page switch between the two for the focused item.
 */
defineProps<{ inSheet?: boolean }>()
const hub = useHub()
const h = hub.history.state
const focused = computed(() => hub.queue.focused.value)

function switchTo(mode: 'user' | 'page') {
  const item = focused.value
  hub.recordMode.value = mode
  if (!item) return
  if (mode === 'page') hub.showRecord({ type: 'page', id: item.page.id, title: item.page.title || '' })
  else if (item.comment.author && item.comment.author.name && !item.comment.author.hidden) hub.showRecord({ type: 'user', name: item.comment.author.name })
}

function retry() {
  if (h.subject) hub.history.show(h.subject, { force: true })
}
</script>

<template>
  <div class="ext-glowthread-record gt:flex gt:flex-col">
    <div v-if="!inSheet && focused" class="gt:flex gt:gap-1 gt:border-b gt:border-border gt:p-2" role="group" :aria-label="t('admin-record')">
      <button
        v-for="m in (['user', 'page'] as const)"
        :key="m"
        type="button"
        class="gt-hit gt:inline-flex gt:h-7 gt:flex-1 gt:items-center gt:justify-center gt:gap-1.5 gt:rounded-control gt:text-meta gt:transition-colors"
        :class="h.subject && h.subject.type === m ? 'gt:bg-surface-strong gt:font-medium gt:text-fg' : 'gt:text-fg-muted gt:hover:bg-surface-subtle gt:hover:text-fg'"
        :aria-pressed="h.subject && h.subject.type === m ? 'true' : 'false'"
        @click="switchTo(m)"
      >
        <UserRound v-if="m === 'user'" class="gt:size-icon-sm" aria-hidden="true" />
        <FileText v-else class="gt:size-icon-sm" aria-hidden="true" />
        {{ t('admin-record-mode-' + m) }}
      </button>
    </div>

    <div class="gt:p-4" :aria-busy="h.status === 'loading' ? 'true' : 'false'">
      <div v-if="!h.subject" class="gt:py-6 gt:text-center gt:text-meta gt:text-fg-muted">{{ t('admin-record-empty') }}</div>
      <div v-else-if="h.status === 'loading'" class="ext-glowthread-loading gt:flex gt:flex-col gt:gap-3" aria-hidden="true">
        <div class="gt:flex gt:items-center gt:gap-3">
          <Skeleton class="gt:size-10 gt:rounded-avatar" />
          <Skeleton class="gt:h-4 gt:w-28" />
        </div>
        <Skeleton class="gt:h-3.5 gt:w-4/5" />
        <Skeleton class="gt:h-3.5 gt:w-3/5" />
        <Skeleton class="gt:h-16 gt:w-full" />
      </div>
      <Notice v-else-if="h.status === 'error'" tone="danger" :action="t('retry')" @action="retry">
        {{ errorMessage(h.error) }}
      </Notice>
      <!-- Keyed: a half-confirmed page state must not carry over to the next page. -->
      <UserRecord v-else-if="h.user" :key="'u:' + h.user.user.name" :data="h.user" />
      <PageRecord v-else-if="h.page" :key="'p:' + h.page.page.id" :data="h.page" />
    </div>
  </div>
</template>
