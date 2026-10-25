<script setup lang="ts">
import { computed } from 'vue'
import type { Sort } from '@/shared/types'
import { Button } from '@/ui/button'
import { Spinner } from '@/ui/spinner'
import CommentItem from './CommentItem.vue'
import PopularStrip from './PopularStrip.vue'
import LoadingState from './LoadingState.vue'
import ErrorState from './ErrorState.vue'
import EmptyState from './EmptyState.vue'
import { useStore } from '../context'
import { errorMessage } from '@/lib/errors'
import { t } from '@/lib/i18n'

/** One sort order: popular strip (Newest only), pinned comments, the list and Load more. */
const props = defineProps<{ sort: Sort, portal: HTMLElement, focus: number }>()
const store = useStore()
const slot = computed(() => store.state.lists[props.sort])
const d = computed(() => slot.value.data)
const empty = computed(() => !!d.value && !d.value.pinned.length && !d.value.comments.length)
</script>

<template>
  <div>
    <LoadingState v-if="slot.status === 'loading' && !d" :rows="2" />
    <ErrorState
      v-else-if="slot.status === 'error' && !d"
      :message="errorMessage(slot.error)"
      @retry="store.load(sort).catch(() => undefined)"
    />
    <template v-else-if="d">
      <EmptyState v-if="empty" :can-post="d.viewer.canPost" />
      <template v-else>
        <PopularStrip v-if="sort === 'new' && d.popular.length" :comments="d.popular" class="gt:mt-4" />
        <ol class="ext-glowthread-list gt:flex gt:flex-col" :aria-label="t('list-label')">
          <li v-for="c in d.pinned" :key="'p' + c.id" class="gt:border-b gt:border-border gt:py-(--gt-v-comment-padding-y) gt:last:border-b-0">
            <CommentItem :comment="c" :portal="portal" :focus="focus" />
          </li>
          <li v-for="c in d.comments" :key="c.id" class="gt:border-b gt:border-border gt:py-(--gt-v-comment-padding-y) gt:last:border-b-0">
            <CommentItem :comment="c" :portal="portal" :focus="focus" />
          </li>
        </ol>
        <div v-if="d.next || slot.moreError" class="gt:flex gt:flex-col gt:items-center gt:gap-2 gt:pt-2 gt:pb-4">
          <p v-if="slot.moreError" class="ext-glowthread-error gt:text-meta gt:text-danger" role="alert">{{ errorMessage(slot.moreError) }}</p>
          <Button
            variant="secondary"
            class="ext-glowthread-load-more gt:min-w-40"
            :disabled="slot.loadingMore"
            :aria-busy="slot.loadingMore ? 'true' : 'false'"
            @click="store.loadMore()"
          >
            <Spinner v-if="slot.loadingMore" />
            {{ slot.loadingMore ? t('loading') : slot.moreError ? t('retry') : t('load-more') }}
          </Button>
        </div>
      </template>
    </template>
  </div>
</template>
