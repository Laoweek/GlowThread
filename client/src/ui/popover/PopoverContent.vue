<script setup lang="ts">
import { PopoverContent, PopoverPortal } from 'reka-ui'
import { cn } from '@/lib/cn'

defineOptions({ inheritAttrs: false })
const props = withDefaults(defineProps<{ class?: string, align?: 'start' | 'center' | 'end', side?: 'top' | 'bottom' | 'left' | 'right', sideOffset?: number }>(), {
  align: 'end',
  side: 'bottom',
  sideOffset: 6,
})
</script>

<template>
  <PopoverPortal>
    <PopoverContent
      v-bind="$attrs"
      :align="align"
      :side="side"
      :side-offset="sideOffset"
      :collision-padding="8"
      :class="cn(
        'ext-glowthread-popover gt-anim-pop gt:z-popover gt:rounded-surface gt:border gt:border-overlay-border gt:bg-surface gt:text-body gt:text-fg gt:shadow-overlay',
        props.class,
      )"
    >
      <slot />
    </PopoverContent>
  </PopoverPortal>
</template>
