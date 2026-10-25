<script setup lang="ts">
import { computed, ref } from 'vue'
import { ChevronDown, Check, ListChecks } from '@lucide/vue'
import { DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuItemIndicator } from 'reka-ui'
import type { PageState } from '@/shared/types'
import { DropdownMenu, DropdownMenuTrigger } from '@/ui/dropdown-menu'
import { DropdownMenuContent, DropdownMenuLabel } from '../menu'
import { Button } from '@/ui/button'
import { useStore } from '../context'
import { showError } from '../actions'
import { toast } from '@/ui/toast'
import { t, tParts, num } from '@/lib/i18n'
import type { ModFilter } from '../store'

/**
 * Moderator bar: Awaiting review N and Reported N filter the list to those comments, the page
 * state switch (Open / Locked / Closed, confirmed inline), and Select for bulk actions.
 */
const store = useStore()
const pageState = computed<PageState>(() => (store.data.value ? store.data.value.page.state : 'open'))
const pending = ref<PageState | null>(null)
const busy = ref(false)
const STATES: PageState[] = ['open', 'locked', 'disabled']

function choose(v: unknown) {
  if (typeof v === 'string' && v !== pageState.value) pending.value = v as PageState
}

async function confirmState() {
  if (!pending.value) return
  busy.value = true
  try {
    const r = await store.setPageState(pending.value)
    toast(t('page-state-done-' + r.state))
    pending.value = null
  } catch (e) {
    showError(e)
  } finally {
    busy.value = false
  }
}

function filter(f: ModFilter) {
  store.setModFilter(store.state.modFilter === f ? null : f)
}
</script>

<template>
  <div class="ext-glowthread-modtools gt:flex gt:flex-col gt:gap-2">
    <div class="gt:flex gt:flex-wrap gt:items-center gt:gap-2" role="group" :aria-label="t('mod-tools')">
      <button
        v-for="f in (['held', 'reported'] as const)"
        :key="f"
        type="button"
        class="gt-hit gt:inline-flex gt:h-7 gt:items-center gt:gap-1.5 gt:rounded-pill gt:border gt:px-3 gt:text-meta gt:font-medium gt:transition-colors"
        :class="store.state.modFilter === f
          ? 'gt:border-fg gt:bg-fg gt:text-surface'
          : 'gt:border-border-control gt:text-fg gt:hover:bg-surface-subtle'"
        :aria-pressed="store.state.modFilter === f ? 'true' : 'false'"
        @click="filter(f)"
      >
        {{ t('chip-' + f) }}
        <span class="gt-num">{{ store.state.modCounts ? num(store.state.modCounts[f]) : '…' }}</span>
      </button>

      <DropdownMenu :modal="false">
        <DropdownMenuTrigger
          class="ext-glowthread-menu-trigger gt-hit gt:inline-flex gt:h-7 gt:items-center gt:gap-1 gt:rounded-control gt:px-2 gt:text-meta gt:text-fg gt:hover:bg-surface-subtle gt:data-[state=open]:bg-surface-subtle"
        >
          <span><template v-for="(p, i) in tParts('page-state-label')" :key="i"><span v-if="p === 1" class="gt:font-medium">{{ t('page-state-' + pageState) }}</span><template v-else>{{ p }}</template></template></span>
          <ChevronDown class="gt:size-icon-sm gt:text-fg-muted" aria-hidden="true" />
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start">
          <DropdownMenuLabel>{{ t('page-state-menu') }}</DropdownMenuLabel>
          <DropdownMenuRadioGroup :model-value="pageState" @update:model-value="choose">
            <DropdownMenuRadioItem
              v-for="s in STATES"
              :key="s"
              :value="s"
              class="ext-glowthread-menu-item gt:flex gt:min-h-[2.75rem] gt:cursor-pointer gt:items-start gt:gap-2.5 gt:rounded-control gt:px-2.5 gt:py-1.5 gt:select-none gt:focus-visible:outline-offset-[calc(var(--gt-v-focus-ring-width)*-1)] gt:data-highlighted:bg-surface-subtle gt:sm:min-h-8"
            >
              <span class="gt:mt-1 gt:inline-flex gt:size-icon gt:shrink-0 gt:items-center gt:justify-center">
                <DropdownMenuItemIndicator><Check class="gt:size-icon gt:text-accent-text" aria-hidden="true" /></DropdownMenuItemIndicator>
              </span>
              <span class="gt:flex gt:flex-col">
                <span class="gt:text-body gt:text-fg">{{ t('page-state-' + s) }}</span>
                <span class="gt:text-meta gt:text-fg-muted">{{ t('page-state-' + s + '-help') }}</span>
              </span>
            </DropdownMenuRadioItem>
          </DropdownMenuRadioGroup>
        </DropdownMenuContent>
      </DropdownMenu>

      <Button
        size="sm"
        :variant="store.state.manage ? 'subtle' : 'ghost'"
        class="gt:ml-auto"
        :aria-pressed="store.state.manage ? 'true' : 'false'"
        @click="store.setManage(!store.state.manage)"
      >
        <ListChecks class="gt:size-icon" aria-hidden="true" />{{ t('manage-mode') }}
      </Button>
    </div>

    <div
      v-if="pending"
      class="gt:flex gt:flex-wrap gt:items-center gt:gap-2 gt:rounded-control gt:bg-warning-subtle gt:px-3 gt:py-2"
      role="group"
      :aria-label="t('page-state-confirm-' + pending)"
    >
      <p class="gt:mr-auto gt:text-body gt:text-warning">{{ t('page-state-confirm-' + pending) }}</p>
      <Button size="sm" variant="ghost" @click="pending = null">{{ t('cancel') }}</Button>
      <Button size="sm" variant="secondary" :disabled="busy" @click="confirmState">{{ t('page-state-apply-' + pending) }}</Button>
    </div>
  </div>
</template>
