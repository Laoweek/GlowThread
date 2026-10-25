<script setup lang="ts">
import type { LogEntry } from '@/shared/types'
import RelativeTime from '@/shared/RelativeTime.vue'
import { commentPermalink } from '@/lib/wiki'
import { revealInvisible } from '@/lib/names'
import { t } from '@/lib/i18n'
import { LOG_ACTIONS } from '../format'

/**
 * Recent log entries: what was done, to which floor, by whom, when and why. On wikis that came
 * from FlowThread the page record also lists FlowThread's own entries (enable, disable, import).
 */
defineProps<{ entries: LogEntry[], empty: string, limit?: number }>()
const label = (action: string) => (LOG_ACTIONS.indexOf(action) >= 0 ? t('admin-log-' + action) : action)
</script>

<template>
  <div v-if="!entries.length" class="gt:text-meta gt:text-fg-muted">{{ empty }}</div>
  <ul v-else class="gt:flex gt:flex-col gt:gap-1.5 gt:text-meta">
    <li v-for="e in entries.slice(0, limit || 6)" :key="e.id" class="gt:flex gt:flex-col">
      <div class="gt:flex gt:flex-wrap gt:items-center gt:gap-x-1.5 gt:text-fg-muted">
        <span class="gt:font-medium gt:text-fg">{{ label(e.action) }}</span>
        <a v-if="e.commentId && e.seq" :href="commentPermalink(e.commentId)" target="_blank" rel="noopener" class="gt-num gt:text-fg gt:hover:underline">#{{ e.seq }}</a>
        <span aria-hidden="true">·</span>
        <span dir="auto">{{ e.performer ? revealInvisible(e.performer) : t('admin-log-hidden') }}</span>
        <span aria-hidden="true">·</span>
        <RelativeTime :iso="e.timestamp" />
      </div>
      <div v-if="e.reason" class="gt:text-fg-muted" dir="auto">{{ e.reason }}</div>
    </li>
  </ul>
</template>
