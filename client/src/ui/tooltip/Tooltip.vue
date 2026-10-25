<script setup lang="ts">
import { TooltipContent, TooltipPortal, TooltipRoot, TooltipTrigger } from 'reka-ui'

/**
 * A short hint on hover and on keyboard focus. The trigger slot must be focusable.
 * Needs a TooltipProvider higher up (the app root has one).
 */
withDefaults(defineProps<{ content: string, side?: 'top' | 'bottom' }>(), { side: 'top' })
</script>

<template>
  <TooltipRoot>
    <TooltipTrigger as-child>
      <slot />
    </TooltipTrigger>
    <TooltipPortal>
      <TooltipContent
        :side="side"
        :side-offset="6"
        :collision-padding="8"
        class="gt-anim-pop gt-num gt:z-popover gt:rounded-control gt:bg-fg gt:px-2 gt:py-1 gt:text-meta gt:text-surface gt:shadow-overlay"
      >
        {{ content }}
      </TooltipContent>
    </TooltipPortal>
  </TooltipRoot>
</template>
