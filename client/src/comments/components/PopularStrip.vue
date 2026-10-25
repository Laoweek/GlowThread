<script setup lang="ts">
import { inject } from 'vue'
import { Heart, ChevronRight } from '@lucide/vue'
import type { Comment } from '@/shared/types'
import { Avatar } from '@/ui/avatar'
import { REVEAL, useStore } from '../context'
import { plainText } from '@/lib/segments'
import { displayName } from '@/lib/names'
import { t, tParts, num } from '@/lib/i18n'

/** In Newest: the most liked comments in a compact strip; each shows its conversation in place. */
defineProps<{ comments: Comment[] }>()
const reveal = inject(REVEAL, () => undefined)
const store = useStore()
const nameOf = (c: Comment) => (c.author.name ? displayName(c.author.name, store.canModerate.value) : t('user-hidden'))
</script>

<template>
  <section class="ext-glowthread-popular gt:rounded-surface gt:bg-surface-subtle gt:p-1" aria-labelledby="gt-popular-heading">
    <h3 id="gt-popular-heading" class="gt:px-2.5 gt:pt-1.5 gt:pb-1 gt:text-meta gt:font-medium gt:text-fg-muted">{{ t('popular') }}</h3>
    <ul>
      <li v-for="c in comments" :key="c.id">
        <button
          type="button"
          class="gt:flex gt:min-h-[2.75rem] gt:w-full gt:items-center gt:gap-2.5 gt:rounded-control gt:px-2.5 gt:py-1.5 gt:text-start gt:hover:bg-surface-subtle"
          :aria-label="t('popular-open', nameOf(c), plainText(c.segments))"
          @click="reveal(c.id)"
        >
          <Avatar :name="c.author.anon ? null : c.author.name" size="sm" />
          <span class="gt:min-w-0 gt:flex-1 gt:truncate gt:text-body">
            <template v-for="(p, i) in tParts('popular-line', 2)" :key="i"><span v-if="p === 1" class="gt:font-medium">{{ nameOf(c) }}</span><span v-else-if="p === 2" dir="auto">{{ plainText(c.segments) }}</span><span v-else class="gt:text-fg-muted">{{ p }}</span></template>
          </span>
          <span class="gt-num gt:inline-flex gt:shrink-0 gt:items-center gt:gap-1 gt:text-meta gt:text-fg-muted">
            <Heart class="gt:size-icon-sm" aria-hidden="true" />{{ num(c.likes) }}
          </span>
          <ChevronRight class="gt-flip-rtl gt:size-icon gt:shrink-0 gt:text-fg-muted" aria-hidden="true" />
        </button>
      </li>
    </ul>
  </section>
</template>
