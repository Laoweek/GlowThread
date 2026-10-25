<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { Comment } from '@/shared/types'
import { useStore } from '../context'
import { like } from '../actions'
import { t, num } from '@/lib/i18n'

/**
 * Like: one tap, the count changes at once (optimistic, then confirmed by the server).
 * Own comments show the count without a button. Logged-out readers get a login prompt, and
 * new accounts the new-account gate holds are told when they can like (like()).
 * The icon is Lucide's heart, written out as SVG because every comment draws it (cheaper than
 * the component). tests/icons.test.ts keeps the markup equal to Lucide's.
 */
const props = defineProps<{ comment: Comment }>()
const store = useStore()
const pop = ref(false)
const liked = computed(() => props.comment.viewer.liked)
const own = computed(() => props.comment.viewer.own)
const gated = computed(() => !!store.viewer.value && store.viewer.value.reactBlockedReason === 'newaccount')
const interactive = computed(() => !own.value && (props.comment.viewer.canLike || mw.user.isAnon() || gated.value) && props.comment.status === 'visible')

watch(liked, (v) => {
  pop.value = v
})
</script>

<template>
  <button
    v-if="interactive"
    type="button"
    class="ext-glowthread-like gt-hit gt:-ml-1.5 gt:inline-flex gt:h-7 gt:items-center gt:gap-1 gt:rounded-control gt:px-1.5 gt:text-meta gt:transition-colors gt:hover:bg-surface-subtle"
    :class="liked ? 'gt:text-accent-text' : 'gt:text-fg-muted gt:hover:text-fg'"
    :aria-pressed="liked ? 'true' : 'false'"
    :aria-label="t('like-label', comment.likes)"
    @click="like(store, comment)"
  >
    <svg
      xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
      class="lucide lucide-heart gt:size-icon"
      :class="[liked ? 'gt:fill-accent gt:text-accent' : '', pop ? 'gt-anim-like' : '']"
      aria-hidden="true"
      @animationend="pop = false"
    ><path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5" /></svg>
    <span class="gt-num">{{ num(comment.likes) }}</span>
  </button>
  <span v-else class="ext-glowthread-like gt:-ml-1.5 gt:inline-flex gt:h-7 gt:items-center gt:gap-1 gt:px-1.5 gt:text-meta gt:text-fg-muted">
    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-heart gt:size-icon" aria-hidden="true"><path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5" /></svg>
    <span class="gt-num" aria-hidden="true">{{ num(comment.likes) }}</span>
    <span class="gt-sr-only">{{ t('like-count', comment.likes) }}</span>
  </span>
</template>
