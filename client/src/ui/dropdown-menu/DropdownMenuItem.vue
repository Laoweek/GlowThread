<script setup lang="ts">
import { DropdownMenuItem } from 'reka-ui'
import { cn } from '@/lib/cn'

// Keyboard focus draws the accent ring inside the item (the tint alone is too faint
// to show which item is current). Items are 44 px tall on phones.
const props = defineProps<{ class?: string, variant?: 'default' | 'danger' }>()
</script>

<template>
  <DropdownMenuItem
    :class="cn(
      'ext-glowthread-menu-item gt:flex gt:min-h-[2.75rem] gt:cursor-pointer gt:items-center gt:gap-2.5 gt:rounded-control gt:px-2.5 gt:py-1.5 gt:select-none gt:focus-visible:outline-offset-[calc(var(--gt-v-focus-ring-width)*-1)] gt:data-highlighted:bg-surface-subtle gt:data-disabled:cursor-default gt:data-disabled:opacity-50 gt:sm:min-h-8',
      variant === 'danger' ? 'gt:text-danger gt:data-highlighted:bg-danger-subtle' : 'gt:text-fg',
      props.class,
    )"
  >
    <slot />
  </DropdownMenuItem>
</template>
