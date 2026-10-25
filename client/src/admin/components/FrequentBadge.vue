<script setup lang="ts">
import { t, num } from '@/lib/i18n'
import { revealInvisible } from '@/lib/names'

/**
 * How many loaded items a frequent reporter filed, as a button that filters the queue by
 * them. Its own component so that a changed count re-renders the badges, not every item.
 */
defineProps<{
  name: string
  /** Reporter name -> loaded items they reported; one shared record, updated key by key. */
  counts: Record<string, number>
}>()
defineEmits<{ filter: [name: string] }>()
</script>

<template>
  <button
    v-if="counts[name]"
    type="button"
    data-glowthread-tone="warning"
    class="ext-glowthread-badge gt-hit gt:ml-1 gt:inline-flex gt:h-5 gt:items-center gt:rounded-mark gt:bg-warning-subtle gt:px-1.5 gt:text-small gt:leading-none gt:font-medium gt:text-warning gt:hover:underline"
    :aria-label="t('admin-frequent-filter', revealInvisible(name), num(counts[name]))"
    @click.stop="$emit('filter', name)"
  >
    {{ t('admin-frequent', num(counts[name])) }}
  </button>
</template>
