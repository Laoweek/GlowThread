<script setup lang="ts">
import { computed, ref } from 'vue'
import { Button } from '@/ui/button'
import { useStore } from '../context'
import { moderate } from '../actions'
import type { ModAction } from '../store'
import { t } from '@/lib/i18n'
import { restoreLabelFor } from '@/shared/restore'

/** Select mode: act on the selected comments at once. */
const store = useStore()
const busy = ref(false)
const count = computed(() => store.state.selected.length)
// Restore says what it does to the selection, as on a single comment, so it never publishes
// held text without saying so.
const restoreKey = computed(() => restoreLabelFor(store.state.selected
  .map((id) => store.find(id))
  .filter((c): c is NonNullable<typeof c> => !!c && c.status === 'deleted')))

async function run(action: ModAction) {
  // All of them: the action sends them 50 at a time.
  const ids = store.state.selected.slice()
  if (!ids.length) return
  busy.value = true
  const ok = await moderate(store, ids, action)
  busy.value = false
  if (ok) store.state.selected = []
}
</script>

<template>
  <div
    class="ext-glowthread-bulk-bar gt-sticky-bar gt:sticky gt:bottom-[72px] gt:z-10 gt:sm:bottom-4 gt:flex gt:flex-wrap gt:items-center gt:gap-2 gt:rounded-surface gt:border gt:border-overlay-border gt:bg-surface gt:px-3 gt:py-2 gt:shadow-overlay"
    role="toolbar"
    :aria-label="t('bulk-label')"
  >
    <p class="gt-num gt:mr-auto gt:text-body" aria-live="polite">{{ t('bulk-selected', count) }}</p>
    <Button size="sm" :disabled="!count || busy" @click="run('approve')">{{ t('approve') }}</Button>
    <Button size="sm" :disabled="!count || busy" @click="run('dismiss')">{{ t('dismiss') }}</Button>
    <Button size="sm" :disabled="!count || busy" @click="run('restore')">{{ t(restoreKey) }}</Button>
    <Button size="sm" variant="danger" :disabled="!count || busy" @click="run('delete')">{{ t('hide') }}</Button>
    <Button size="sm" variant="ghost" @click="store.setManage(false)">{{ t('bulk-exit') }}</Button>
  </div>
</template>
