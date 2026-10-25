<script setup lang="ts">
import { cn } from '@/lib/cn'

/** A quiet status label: Pinned, Awaiting review, Reported, Only you can see this. Tints stay muted; text meets 4.5:1. */
const props = withDefaults(defineProps<{ tone?: 'neutral' | 'accent' | 'warning' | 'danger' | 'success', class?: string }>(), { tone: 'neutral' })
const TONES = {
  neutral: 'gt:bg-surface-subtle gt:text-fg-muted',
  accent: 'gt:bg-accent-badge gt:text-accent-text',
  warning: 'gt:bg-warning-subtle gt:text-warning',
  danger: 'gt:bg-danger-subtle gt:text-danger',
  success: 'gt:bg-success-subtle gt:text-success',
}
</script>

<template>
  <span
    :data-glowthread-tone="tone"
    :class="cn(
      'ext-glowthread-badge gt:inline-flex gt:h-5 gt:shrink-0 gt:items-center gt:gap-1 gt:rounded-mark gt:px-1.5 gt:text-small gt:leading-none gt:font-medium gt:whitespace-nowrap',
      TONES[tone],
      props.class,
    )"
  >
    <slot />
  </span>
</template>
