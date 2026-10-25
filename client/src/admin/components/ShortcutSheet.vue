<script setup lang="ts">
import { Sheet, SheetContent } from '@/ui/sheet'
import { t } from '@/lib/i18n'
import { SHORTCUTS } from '../keyboard'

/** ? opens the keyboard shortcuts. The keys never fire while typing in a field. */
const open = defineModel<boolean>('open', { default: false })
</script>

<template>
  <Sheet v-model:open="open">
    <SheetContent :title="t('admin-shortcuts-title')" :description="t('admin-shortcuts-note')" :close-label="t('close')">
      <div class="gt:flex gt:flex-col gt:gap-4 gt:p-4">
        <dl class="gt:grid gt:grid-cols-[auto_minmax(0,1fr)] gt:items-center gt:gap-x-4 gt:gap-y-2.5">
          <template v-for="s in SHORTCUTS" :key="s.command">
            <dt class="gt:flex gt:gap-1"><kbd v-for="k in s.keys" :key="k" class="gt-kbd gt:min-w-6 gt:text-meta">{{ k }}</kbd></dt>
            <dd class="gt:text-body gt:text-fg">{{ t('admin-key-' + s.command) }}</dd>
          </template>
          <dt class="gt:flex gt:gap-1"><kbd class="gt-kbd gt:min-w-6 gt:text-meta">F8</kbd></dt>
          <dd class="gt:text-body gt:text-fg">{{ t('admin-key-toast') }}</dd>
        </dl>
        <div class="gt:text-meta gt:text-fg-muted">{{ t('admin-shortcuts-note') }}</div>
      </div>
    </SheetContent>
  </Sheet>
</template>
