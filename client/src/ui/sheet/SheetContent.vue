<script setup lang="ts">
import { DialogClose, DialogContent, DialogOverlay, DialogPortal, DialogTitle, DialogDescription } from 'reka-ui'
import { X } from '@lucide/vue'
import { cn } from '@/lib/cn'

defineOptions({ inheritAttrs: false })
const props = defineProps<{
  /** Visible heading of the sheet. */
  title: string
  /** Read to screen readers when the sheet opens. */
  description?: string
  closeLabel: string
  class?: string
}>()

// Floating parts that live outside the dialog but belong to it (the @mention list, toasts
// with Undo) are marked data-gt-floating: touching them must not close the sheet.
function keepOpen(e: Event) {
  const target = e.target as Element | null
  if (target && target.closest && target.closest('[data-gt-floating]')) e.preventDefault()
}
</script>

<template>
  <DialogPortal>
    <DialogOverlay class="gt-anim-fade gt:fixed gt:top-0 gt:right-0 gt:bottom-0 gt:left-0 gt:z-sheet gt:bg-scrim" />
    <DialogContent
      v-bind="$attrs"
      @pointer-down-outside="keepOpen"
      @focus-outside="keepOpen"
      :class="cn(
        // Phones: a bottom sheet. From 640 px: a side sheet on the right.
        'ext-glowthread-sheet gt-anim-sheet gt:fixed gt:right-0 gt:bottom-0 gt:left-0 gt:z-sheet gt:flex gt:max-h-[85vh] gt:flex-col gt:rounded-t-surface gt:border-t gt:border-overlay-border gt:bg-surface gt:text-fg gt:shadow-overlay gt:outline-none',
        'gt:sm:top-0 gt:sm:left-auto gt:sm:max-h-none gt:sm:w-[min(var(--gt-v-sheet-width),100vw)] gt:sm:rounded-none gt:sm:border-t-0 gt:sm:border-l',
        props.class,
      )"
    >
      <header class="gt:flex gt:min-h-13 gt:shrink-0 gt:items-center gt:gap-2 gt:border-b gt:border-border gt:py-2 gt:pr-2 gt:pl-4">
        <DialogTitle class="gt:min-w-0 gt:flex-1 gt:truncate gt:text-title gt:font-semibold">
          {{ title }}
        </DialogTitle>
        <DialogDescription v-if="description" class="gt-sr-only">
          {{ description }}
        </DialogDescription>
        <DialogClose
          class="gt-hit gt:inline-flex gt:size-9 gt:items-center gt:justify-center gt:rounded-control gt:text-fg-muted gt:hover:bg-surface-subtle gt:hover:text-fg"
          :aria-label="closeLabel"
        >
          <X class="gt:size-icon-xl" aria-hidden="true" />
        </DialogClose>
      </header>
      <div class="gt-scroll gt:min-h-0 gt:flex-1 gt:pb-[env(safe-area-inset-bottom)]">
        <slot />
      </div>
      <slot name="footer" />
    </DialogContent>
  </DialogPortal>
</template>
