<script setup lang="ts">
import { computed } from 'vue'
import { Clock, Flag } from '@lucide/vue'
import type { Comment, QueueItem } from '@/shared/types'
import { Button } from '@/ui/button'
import { useStore } from '../context'
import { moderate } from '../actions'
import { t, tParts, num } from '@/lib/i18n'
import { restoreLabel, restoreOutcome } from '@/shared/restore'
import { groupReports } from '@/shared/reports'
import { revealInvisible } from '@/lib/names'
import type { ReportGroup } from '@/shared/reports'

/**
 * For moderators, under a held, reported or hidden comment: why it is there, and the
 * one-tap decisions (Approve / Dismiss reports / Hide / Restore) in place, as one block above the
 * like row. In the Awaiting review / Reported view the reports come with their reporters and notes (`reports`):
 * one line per reason, as in the moderation hub.
 */
const props = defineProps<{ comment: Comment, reports?: QueueItem['reports'] }>()
const store = useStore()
const c = computed(() => props.comment)
const groups = computed(() => groupReports({ reports: props.reports || [], comment: c.value }))
const holds = computed(() => (c.value.mod ? c.value.mod.holdReasons : []))
const restore = computed(() => restoreOutcome(c.value))
const show = computed(() => c.value.status !== 'visible' || groups.value.length > 0)

function reportedBy(g: ReportGroup): string {
  const names = g.reporters.map(revealInvisible).join(t('list-separator'))
  return g.count > g.reporters.length ? t('mod-reported-by-more', names, num(g.count)) : t('mod-reported-by', names)
}
</script>

<template>
  <div v-if="show && c.mod" class="ext-glowthread-mod-notes gt:mt-2 gt:flex gt:flex-col gt:gap-1.5 gt:text-meta gt:text-fg-muted">
    <p v-if="c.status === 'held'" class="gt:flex gt:items-start gt:gap-1.5">
      <Clock class="gt:mt-[0.2em] gt:size-icon-sm gt:shrink-0 gt:text-warning" aria-hidden="true" />
      <span><template v-for="(p, i) in tParts('mod-held-because')" :key="i"><span v-if="p === 1" class="gt:font-medium gt:text-fg">{{ holds.map((h) => t('hold-' + h)).join(t('list-separator')) || t('hold-unknown') }}</span><template v-else>{{ p }}</template></template></span>
    </p>
    <!-- A deleted comment that was held when deleted, or may have been: Restore says what follows. -->
    <p v-else-if="c.status === 'deleted' && holds.length && restore !== 'plain'" class="gt:flex gt:items-start gt:gap-1.5">
      <Clock class="gt:mt-[0.2em] gt:size-icon-sm gt:shrink-0 gt:text-warning" aria-hidden="true" />
      <span><template v-for="(p, i) in tParts('mod-was-held')" :key="i"><span v-if="p === 1" class="gt:font-medium gt:text-fg">{{ holds.map((h) => t('hold-' + h)).join(t('list-separator')) }}</span><template v-else>{{ p }}</template></template></span>
    </p>
    <div v-for="g in groups" :key="g.reason" class="gt:flex gt:items-start gt:gap-1.5">
      <Flag class="gt:mt-[0.2em] gt:size-icon-sm gt:shrink-0 gt:text-danger" aria-hidden="true" />
      <div class="gt:min-w-0">
        <p>
          <!-- The words around the reason are for screen readers ("Reported for: Spam"). -->
          <template v-for="(p, i) in tParts('mod-reported-because')" :key="i"><span v-if="p === 1" class="gt:font-medium gt:text-fg">{{ t('reason-' + g.reason) }}</span><span v-else class="gt-sr-only">{{ p }}</span></template>
          <span class="gt-num"> ×{{ num(g.count) }}</span>
          <!-- Breaks only at spaces and list marks, so a CJK label (the Chinese for "reported by") never splits mid-word. -->
          <span v-if="g.reporters.length" class="gt:break-keep" dir="auto"> · {{ reportedBy(g) }}</span>
        </p>
        <p v-for="(note, i) in g.notes" :key="i" class="gt:font-text" dir="auto">“{{ note }}”</p>
      </div>
    </div>
    <div class="gt:mt-0.5 gt:flex gt:items-center gt:gap-2">
      <template v-if="c.status === 'held'">
        <Button size="sm" variant="secondary" @click="moderate(store, [c.id], 'approve')">{{ t('approve') }}</Button>
        <Button size="sm" variant="danger" @click="moderate(store, [c.id], 'delete')">{{ t('hide') }}</Button>
      </template>
      <template v-else-if="c.status === 'visible' && groups.length">
        <Button size="sm" variant="secondary" @click="moderate(store, [c.id], 'dismiss')">{{ t('dismiss') }}</Button>
        <Button size="sm" variant="danger" @click="moderate(store, [c.id], 'delete')">{{ t('hide') }}</Button>
      </template>
      <Button v-else-if="c.status === 'deleted'" size="sm" variant="secondary" @click="moderate(store, [c.id], 'restore')">
        {{ t(restoreLabel(restore)) }}
      </Button>
    </div>
  </div>
</template>
