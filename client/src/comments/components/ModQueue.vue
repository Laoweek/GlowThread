<script setup lang="ts">
import { computed } from 'vue'
import { Button } from '@/ui/button'
import { Spinner } from '@/ui/spinner'
import CommentItem from './CommentItem.vue'
import LoadingState from './LoadingState.vue'
import ErrorState from './ErrorState.vue'
import { useStore } from '../context'
import { errorMessage } from '@/lib/errors'
import { plainText } from '@/lib/segments'
import { revealInvisible } from '@/lib/names'
import { t } from '@/lib/i18n'

/** The list filtered to this page's held or reported comments, each with its context. */
defineProps<{ portal: HTMLElement }>()
const store = useStore()
const q = computed(() => store.state.queue)
</script>

<template>
  <div>
    <LoadingState v-if="q.status === 'loading'" :rows="2" />
    <ErrorState v-else-if="q.status === 'error'" :message="errorMessage(q.error)" @retry="store.state.modFilter && store.setModFilter(store.state.modFilter)" />
    <!-- Empty with more to load (the server skipped rows): Load more, not "none". -->
    <p v-else-if="!q.items.length && !q.next" class="ext-glowthread-empty gt:px-4 gt:py-10 gt:text-center gt:text-body gt:text-fg-muted">
      {{ t('queue-empty-' + store.state.modFilter) }}
    </p>
    <ol v-else class="ext-glowthread-list gt:flex gt:flex-col">
      <li v-for="item in q.items" :key="item.comment.id" class="gt:border-b gt:border-border gt:py-(--gt-v-comment-padding-y) gt:last:border-b-0">
        <p v-if="item.context" class="gt:mb-2 gt:truncate gt:text-meta gt:text-fg-muted">
          {{ t('queue-context', item.context.author.name ? revealInvisible(item.context.author.name) : t('user-hidden'), plainText(item.context.segments)) }}
        </p>
        <!-- The reports and their reporters show with the decisions, in the comment's own column. -->
        <CommentItem :comment="item.comment" :anchor="false" :portal="portal" surface="queue" :reports="item.reports" />
      </li>
    </ol>
    <div v-if="q.next" class="gt:flex gt:justify-center gt:pt-2 gt:pb-4">
      <Button variant="secondary" class="ext-glowthread-load-more gt:min-w-40" :disabled="q.loadingMore" @click="store.loadMoreQueue()">
        <Spinner v-if="q.loadingMore" />{{ q.loadingMore ? t('loading') : t('load-more') }}
      </Button>
    </div>
  </div>
</template>
