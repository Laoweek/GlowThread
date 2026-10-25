<script setup lang="ts">
import { DropdownMenuContent, DropdownMenuPortal } from 'reka-ui'
import { cn } from '@/lib/cn'

defineOptions({ inheritAttrs: false })
const props = withDefaults(defineProps<{ class?: string, align?: 'start' | 'center' | 'end', sideOffset?: number }>(), {
  align: 'end',
  sideOffset: 4,
})
</script>

<template>
  <DropdownMenuPortal>
    <DropdownMenuContent
      v-bind="$attrs"
      :align="align"
      :side-offset="sideOffset"
      :collision-padding="8"
      :class="cn(
        'ext-glowthread-menu gt-anim-pop gt-scroll gt:z-popover gt:min-w-36 gt:max-h-(--reka-dropdown-menu-content-available-height) gt:rounded-surface gt:border gt:border-overlay-border gt:bg-surface gt:p-1 gt:text-body gt:text-fg gt:shadow-overlay',
        props.class,
      )"
    >
      <slot />
    </DropdownMenuContent>
  </DropdownMenuPortal>
</template>
