<script setup lang="ts">
import { computed, nextTick } from 'vue'
import type { Comment } from '@/shared/types'
import { Popover, PopoverAnchor, PopoverContent } from '@/ui/popover'
import { Sheet, SheetContent } from '@/ui/sheet'
import ReportForm from './ReportForm.vue'
import { t } from '@/lib/i18n'

/**
 * Report opens a small popover next to the ⋯ button, or a bottom sheet on phones.
 * One instance serves the whole section.
 */
const props = defineProps<{ comment: Comment | null, anchor: HTMLElement | null, phone: boolean }>()
const emit = defineEmits<{ close: [] }>()
const open = computed({
  get: () => !!props.comment,
  set: (v: boolean) => {
    if (!v) emit('close')
  },
})

function returnFocus(e: Event) {
  // No trigger element (the menu opened this), so give focus back to ⋯ here.
  e.preventDefault()
  const a = props.anchor
  nextTick(() => a && a.focus())
}
</script>

<template>
  <Sheet v-if="phone" v-model:open="open">
    <SheetContent :title="t('report-title')" :close-label="t('close')" @close-auto-focus="returnFocus">
      <div class="gt:p-4">
        <ReportForm v-if="comment" :key="comment.id" :comment="comment" :heading="false" @close="emit('close')" />
      </div>
    </SheetContent>
  </Sheet>
  <Popover v-else v-model:open="open">
    <PopoverAnchor :reference="anchor || undefined" />
    <PopoverContent class="gt:w-80 gt:p-4" align="end" :aria-label="t('report-title')" @close-auto-focus="returnFocus">
      <ReportForm v-if="comment" :key="comment.id" :comment="comment" :heading="true" @close="emit('close')" />
    </PopoverContent>
  </Popover>
</template>
