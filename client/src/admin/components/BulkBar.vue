<script setup lang="ts">
import { computed } from 'vue'
import { Check, RotateCcw, Trash2, X } from '@lucide/vue'
import { Button } from '@/ui/button'
import { t, num } from '@/lib/i18n'
import { useHub } from '../context'
import { actionFor } from '../moderation'
import type { Kind } from '../moderation'
import { restoreLabelFor } from '@/shared/restore'

/**
 * Acts on the selected comments at once. Each button says how many of the selection it
 * applies to when that is not all of them (Restore only applies to deleted comments).
 */
const hub = useHub()
const q = hub.queue
const selected = computed(() => q.state.items.filter((x) => q.state.selected.indexOf(x.comment.id) >= 0))
const count = computed(() => q.state.selected.length)
const applies = (kind: Kind) => selected.value.filter((x) => actionFor(kind, x.comment)).length
const busy = computed(() => q.state.busy.length > 0)
// Restore says what it does to the selection, as the item's own Restore, so it never publishes
// held text without saying so.
const restoreKey = computed(() => restoreLabelFor(selected.value.filter((x) => x.comment.status === 'deleted').map((x) => x.comment)))
const label = (a: { kind: Kind, label: string }) => (a.kind === 'restore' ? restoreKey.value : a.label)

const ACTIONS: Array<{ kind: Kind, label: string, icon: unknown, variant: 'secondary' | 'danger' | 'ghost' }> = [
  { kind: 'pass', label: 'approve', icon: Check, variant: 'secondary' },
  { kind: 'delete', label: 'delete', icon: Trash2, variant: 'danger' },
  { kind: 'restore', label: 'restore', icon: RotateCcw, variant: 'secondary' },
  { kind: 'dismiss', label: 'dismiss', icon: null, variant: 'ghost' },
]

function run(kind: Kind) {
  hub.act(kind, q.state.selected.slice())
}
</script>

<template>
  <div
    class="ext-glowthread-bulk-bar gt-sticky-bar gt:sticky gt:bottom-[72px] gt:z-10 gt:mt-3 gt:flex gt:flex-wrap gt:items-center gt:gap-2 gt:rounded-surface gt:border gt:border-overlay-border gt:bg-surface gt:px-3 gt:py-2 gt:shadow-overlay gt:sm:bottom-4"
    role="toolbar"
    :aria-label="t('admin-bulk-label')"
  >
    <div class="gt-num gt:mr-auto gt:text-body gt:font-medium" aria-live="polite">{{ t('bulk-selected', num(count)) }}</div>
    <template v-for="a in ACTIONS" :key="a.kind">
      <Button
        v-if="applies(a.kind)"
        size="sm"
        :variant="a.variant"
        :disabled="busy || hub.blocked.value"
        @click="run(a.kind)"
      >
        <component :is="a.icon" v-if="a.icon" class="gt:size-icon-sm" aria-hidden="true" />
        {{ t(label(a)) }}<span v-if="applies(a.kind) !== count" class="gt-num gt:text-fg-muted">{{ num(applies(a.kind)) }}</span>
      </Button>
    </template>
    <!-- An icon on phones, so the bar stays one line. -->
    <Button size="sm" variant="ghost" :aria-label="t('admin-bulk-clear')" @click="q.state.selected = []">
      <X class="gt:size-icon-sm" aria-hidden="true" /><span class="gt:hidden gt:sm:inline">{{ t('admin-bulk-clear') }}</span><kbd class="gt-kbd gt-kbd-hint" aria-hidden="true">Esc</kbd>
    </Button>
  </div>
</template>
