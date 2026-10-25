<script setup lang="ts">
import { computed } from 'vue'
import { Link2, Flag, Trash2, Pin, PinOff, EyeOff, RotateCcw, Check, CheckCheck, History } from '@lucide/vue'
import type { Comment } from '@/shared/types'
import { DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuLabel } from '@/ui/dropdown-menu'
import { useOverlays, useStore } from '../context'
import { copyLink, moderate } from '../actions'
import { t } from '@/lib/i18n'
import { userRecordUrl } from '@/lib/wiki'
import { restoreLabel, restoreOutcome } from '@/shared/restore'
import { extras } from '../lazy'

/** The items of the ⋯ menu (CommentMenu.vue), in the menu module. */
const props = defineProps<{ comment: Comment, trigger: HTMLElement | null }>()
const emit = defineEmits<{ delete: [] }>()
const store = useStore()
const overlays = useOverlays()

const c = computed(() => props.comment)
const mod = computed(() => store.canModerate.value)
const canReport = computed(() => c.value.viewer.canReport && !c.value.viewer.own && c.value.status === 'visible')
const canSelfDelete = computed(() => c.value.viewer.canDelete && !mod.value && c.value.status !== 'deleted')
const authorName = computed(() => (c.value.author && !c.value.author.hidden ? c.value.author.name : null))
// Restore, Restore for review or Restore and publish: what restoring this deleted comment does.
const restoreKey = computed(() => restoreLabel(restoreOutcome(c.value)))
// The report form is in the extras module: a reader who may report gets it while reading the menu.
if (canReport.value) extras.load()

// The report form takes focus as the menu closes. A form mounted after this menu (the extras
// module came with this menu) counts as part of it, so the menu would take focus back to ⋯
// and close the form: it keeps focus where it is then.
let reporting = false

function report() {
  const el = props.trigger
  reporting = !!extras.module.value
  // Let the menu close and hand focus back before the report form takes it.
  window.setTimeout(() => overlays.openReport(c.value, el as HTMLElement), 0)
}

function onCloseAutoFocus(e: Event) {
  if (reporting) e.preventDefault()
  reporting = false
}
</script>

<template>
  <DropdownMenuContent @close-auto-focus="onCloseAutoFocus">
    <DropdownMenuItem @select="copyLink(c)">
      <Link2 class="gt:size-icon gt:text-fg-muted" aria-hidden="true" />{{ t('copy-link') }}
    </DropdownMenuItem>
    <DropdownMenuItem v-if="canReport" :disabled="c.viewer.reported" @select="report">
      <Flag class="gt:size-icon gt:text-fg-muted" aria-hidden="true" />{{ c.viewer.reported ? t('reported') : t('report') }}
    </DropdownMenuItem>
    <DropdownMenuItem v-if="canSelfDelete" variant="danger" @select="emit('delete')">
      <Trash2 class="gt:size-icon" aria-hidden="true" />{{ t('delete') }}
    </DropdownMenuItem>
    <template v-if="mod">
      <DropdownMenuSeparator />
      <DropdownMenuLabel>{{ t('mod-label') }}</DropdownMenuLabel>
      <DropdownMenuItem v-if="c.depth === 0 && c.status === 'visible'" @select="moderate(store, [c.id], c.pinned ? 'unpin' : 'pin')">
        <PinOff v-if="c.pinned" class="gt:size-icon gt:text-fg-muted" aria-hidden="true" />
        <Pin v-else class="gt:size-icon gt:text-fg-muted" aria-hidden="true" />
        {{ c.pinned ? t('unpin') : t('pin') }}
      </DropdownMenuItem>
      <DropdownMenuItem v-if="c.status === 'held'" @select="moderate(store, [c.id], 'approve')">
        <Check class="gt:size-icon gt:text-fg-muted" aria-hidden="true" />{{ t('approve') }}
      </DropdownMenuItem>
      <DropdownMenuItem v-if="c.mod && c.mod.openReports > 0" @select="moderate(store, [c.id], 'dismiss')">
        <CheckCheck class="gt:size-icon gt:text-fg-muted" aria-hidden="true" />{{ t('dismiss') }}
      </DropdownMenuItem>
      <DropdownMenuItem v-if="c.status === 'visible' || c.status === 'held'" variant="danger" @select="moderate(store, [c.id], 'delete')">
        <EyeOff class="gt:size-icon" aria-hidden="true" />{{ t('hide') }}
      </DropdownMenuItem>
      <DropdownMenuItem v-if="c.status === 'deleted'" @select="moderate(store, [c.id], 'restore')">
        <!-- Restoring publishes: say so when the comment was once held for review. -->
        <RotateCcw class="gt:size-icon gt:text-fg-muted" aria-hidden="true" />{{ t(restoreKey) }}
      </DropdownMenuItem>
      <DropdownMenuItem v-if="authorName" as-child>
        <a :href="userRecordUrl(authorName)">
          <History class="gt:size-icon gt:text-fg-muted" aria-hidden="true" />{{ t('user-record') }}
        </a>
      </DropdownMenuItem>
    </template>
  </DropdownMenuContent>
</template>
