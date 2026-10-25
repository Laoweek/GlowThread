<script setup lang="ts">
import { computed, inject } from 'vue'
import { X } from '@lucide/vue'
import CommentItem from './CommentItem.vue'
import LoadingState from './LoadingState.vue'
import ErrorState from './ErrorState.vue'
import { FOCUS_HEADING, REVEAL, useStore } from '../context'
import { errorMessage } from '@/lib/errors'
import { t } from '@/lib/i18n'
import { commentPermalink } from '@/lib/wiki'

/**
 * A conversation asked for by a permalink (#gt-<id>) or the popular strip whose thread is
 * not in the list: shown above the list with the comment highlighted, until the reader
 * closes it. A comment on another page is only linked, never shown or named here: whoever
 * creates a page picks its title.
 */
defineProps<{ portal: HTMLElement, focus: number }>()
const store = useStore()
const l = computed(() => store.state.linked)
const focusHeading = inject(FOCUS_HEADING, () => undefined)
const reveal = inject(REVEAL, () => undefined)

function close() {
  store.closeLinked()
  focusHeading()
}
</script>

<template>
  <section
    v-if="l.status !== 'idle'"
    class="ext-glowthread-linked gt:rounded-surface gt:border gt:border-border gt:px-3 gt:pt-2 gt:pb-3"
    aria-labelledby="gt-linked-heading"
    :aria-busy="l.status === 'loading' ? 'true' : 'false'"
    data-linked
  >
    <header class="gt:mb-2 gt:flex gt:min-h-8 gt:items-center gt:gap-2">
      <h3 id="gt-linked-heading" class="gt:min-w-0 gt:flex-1 gt:text-meta gt:font-medium gt:text-fg-muted">{{ t('thread-title') }}</h3>
      <button
        type="button"
        class="gt-hit gt:inline-flex gt:size-7 gt:shrink-0 gt:items-center gt:justify-center gt:rounded-control gt:text-fg-muted gt:hover:bg-surface-subtle gt:hover:text-fg"
        :aria-label="t('close')"
        @click="close"
      >
        <X class="gt:size-icon" aria-hidden="true" />
      </button>
    </header>
    <LoadingState v-if="l.status === 'loading'" :rows="2" />
    <ErrorState v-else-if="l.status === 'error'" :message="errorMessage(l.error)" @retry="reveal(l.id)" />
    <p v-else-if="l.status === 'elsewhere' && l.data" class="gt:text-body gt:text-fg" role="note">
      {{ t('thread-other-page') }}
      <a :href="commentPermalink(l.id)" class="gt:font-medium gt:text-accent-text gt:hover:underline">{{ t('thread-elsewhere-open') }}</a>
    </p>
    <CommentItem v-else-if="l.data" :comment="l.data.thread" :anchor="false" :portal="portal" :focus="focus" surface="linked" />
  </section>
</template>
