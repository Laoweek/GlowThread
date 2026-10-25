<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogOverlay, AlertDialogPortal, AlertDialogRoot, AlertDialogTitle } from 'reka-ui'
import { Eraser } from '@lucide/vue'
import type { QueueItem } from '@/shared/types'
import { buttonVariants } from '@/ui/button/variants'
import { plainText } from '@/lib/segments'
import { t } from '@/lib/i18n'
import { useHub } from '../context'

/**
 * Erasing removes a comment's text and author for good. It is the one action without undo,
 * so it asks in a dialog that says so, offers a reason for the log, and starts on Cancel.
 */
const props = defineProps<{ item: QueueItem | null }>()
const emit = defineEmits<{ close: [] }>()
const hub = useHub()
const reason = ref('')
const open = computed({
  get: () => !!props.item,
  set: (v: boolean) => {
    if (!v) emit('close')
  },
})
watch(() => props.item, () => {
  reason.value = ''
})
const excerpt = computed(() => {
  const text = props.item ? plainText(props.item.comment.segments).replace(/\s+/g, ' ').trim() : ''
  const chars = Array.from(text)
  return chars.length > 120 ? chars.slice(0, 119).join('') + '…' : text
})

function erase() {
  const item = props.item
  if (!item) return
  emit('close')
  hub.act('erase', [item.comment.id], { reason: reason.value.trim() })
}
</script>

<template>
  <AlertDialogRoot v-model:open="open">
    <AlertDialogPortal>
      <AlertDialogOverlay class="gt-anim-fade gt:fixed gt:top-0 gt:right-0 gt:bottom-0 gt:left-0 gt:z-sheet gt:bg-scrim" />
      <AlertDialogContent
        class="ext-glowthread-dialog gt-anim-pop gt:fixed gt:top-[12vh] gt:right-4 gt:left-4 gt:z-sheet gt:mx-auto gt:flex gt:max-w-[440px] gt:flex-col gt:gap-3 gt:rounded-surface gt:border gt:border-overlay-border gt:bg-surface gt:p-5 gt:text-fg gt:shadow-overlay gt:outline-none"
      >
        <AlertDialogTitle class="gt:flex gt:items-center gt:gap-2 gt:text-title gt:font-semibold">
          <Eraser class="gt:size-icon-xl gt:text-danger" aria-hidden="true" />{{ t('admin-erase-title') }}
        </AlertDialogTitle>
        <AlertDialogDescription class="gt:text-body gt:text-fg">
          {{ t('admin-erase-body') }}
        </AlertDialogDescription>
        <blockquote v-if="item && excerpt" class="gt:rounded-control gt:bg-surface-subtle gt:px-3 gt:py-2 gt:text-meta gt:text-fg-muted" dir="auto">
          {{ excerpt }}
        </blockquote>
        <label class="gt:flex gt:flex-col gt:gap-1 gt:text-meta gt:text-fg-muted">
          {{ t('admin-reason') }}
          <input
            v-model="reason"
            type="text"
            maxlength="500"
            class="gt:h-8 gt:w-full gt:rounded-control gt:border gt:border-border-control gt:bg-surface gt:px-2.5 gt:text-body gt:text-fg gt:focus:border-accent"
            :placeholder="t('admin-reason-optional')"
          >
        </label>
        <div class="gt:mt-2 gt:flex gt:justify-end gt:gap-2">
          <AlertDialogCancel :class="buttonVariants({ variant: 'secondary', size: 'md' })" data-glowthread-variant="secondary">{{ t('cancel') }}</AlertDialogCancel>
          <AlertDialogAction :class="buttonVariants({ variant: 'danger', size: 'md' })" data-glowthread-variant="danger" @click="erase">{{ t('admin-erase-confirm') }}</AlertDialogAction>
        </div>
      </AlertDialogContent>
    </AlertDialogPortal>
  </AlertDialogRoot>
</template>
