<script setup lang="ts">
import { computed, inject, ref } from 'vue'
import type { Ref } from 'vue'
import { ArrowRight, ExternalLink } from '@lucide/vue'
import type { UserHistoryResponse } from '@/shared/types'
import { Avatar } from '@/ui/avatar'
import { Badge } from '@/ui/badge'
import RelativeTime from '@/shared/RelativeTime.vue'
import { plainText } from '@/lib/segments'
import { blockLogUrl, blockUrl, commentPermalink, contribsUrl, userUrl } from '@/lib/wiki'
import { revealInvisible } from '@/lib/names'
import { t, num } from '@/lib/i18n'
import { useHub } from '../context'
import { queueRoute } from '../router'
import { accountAge, expiry } from '../format'
import LogList from './LogList.vue'

/**
 * An author's record: how old the account is and how much it edits, any current block
 * (with Special:Block and the block log), their comments by status, the reports they filed
 * and how many were upheld, what moderators did to their comments, and recent comments.
 */
const props = defineProps<{ data: UserHistoryResponse }>()
const hub = useHub()
const now = inject<Ref<number>>('gt-now', ref(Date.now()))
const u = computed(() => props.data.user)
const age = computed(() => accountAge(u.value.registration, now.value))
const anon = computed(() => !u.value.registered)
const reports = computed(() => props.data.reports)
/** Share of decided reports that were upheld (the comment was deleted). */
const upheldRate = computed(() => {
  const decided = reports.value.upheld + reports.value.dismissed
  return decided ? Math.round((reports.value.upheld / decided) * 100) : null
})
const recent = computed(() => props.data.comments.slice(0, 5))
// One record shows at a time; a name can contain spaces, which an ID reference cannot.
const headId = 'gt-rec-user'

function excerpt(segments: Parameters<typeof plainText>[0]): string {
  const text = plainText(segments).replace(/\s+/g, ' ').trim()
  const chars = Array.from(text)
  return chars.length > 60 ? chars.slice(0, 59).join('') + '…' : text
}

function theirReports(e: MouseEvent) {
  if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return
  e.preventDefault()
  hub.navigate(queueRoute({ tab: 'reported', reporter: u.value.name }))
}

function allComments(e: MouseEvent) {
  if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return
  e.preventDefault()
  hub.navigate(queueRoute({ tab: 'all', user: u.value.name, subject: 'user' }))
}
</script>

<template>
  <section class="gt:flex gt:flex-col gt:gap-4" :aria-labelledby="headId">
    <header class="gt:flex gt:items-center gt:gap-3">
      <Avatar :name="u.name" :generic="anon" />
      <div class="gt:min-w-0">
        <h3 :id="headId" class="gt:truncate gt:text-title gt:font-semibold">
          <a :href="anon ? contribsUrl(u.name) : userUrl(u.name)" class="gt:text-fg gt:hover:underline" dir="auto">{{ revealInvisible(u.name) }}</a>
        </h3>
        <div class="gt-num gt:text-meta gt:text-fg-muted">
          <template v-if="anon">{{ t('admin-user-ip') }}</template>
          <template v-else>
            <span v-if="age" :class="age.young ? 'gt:font-semibold gt:text-fg' : ''">{{ age.text }}</span>
            <span v-else>{{ t('admin-age-unknown') }}</span>
            · {{ t('admin-user-edits', num(u.editCount || 0)) }}
          </template>
        </div>
        <div v-if="u.groups.length" class="gt:mt-1 gt:flex gt:flex-wrap gt:gap-1">
          <Badge v-for="g in u.groups" :key="g">{{ g }}</Badge>
        </div>
      </div>
    </header>

    <!-- One list of facts: the block first, then counts in aligned columns. -->
    <dl class="gt:grid gt:grid-cols-[auto_minmax(0,1fr)] gt:gap-x-4 gt:gap-y-2.5 gt:text-meta">
      <dt class="gt:text-fg-muted">{{ t('admin-user-block') }}</dt>
      <dd class="gt:flex gt:flex-col gt:gap-1">
        <template v-if="u.blocks.length">
          <div v-for="(b, i) in u.blocks" :key="i" class="gt:flex gt:flex-col gt:gap-0.5">
            <div class="gt:font-medium gt:text-danger">{{ b.sitewide ? t('admin-block-sitewide') : t('admin-block-partial') }}</div>
            <div class="gt:text-fg">{{ t('admin-block-expiry', expiry(b.expiry)) }}<template v-if="b.by"> · {{ t('admin-block-by', b.by) }}</template></div>
            <div v-if="b.reason" class="gt:text-fg" dir="auto">{{ t('admin-block-reason', b.reason) }}</div>
          </div>
        </template>
        <div v-else class="gt:text-fg">{{ t('admin-block-none') }}</div>
        <div class="gt:flex gt:flex-wrap gt:gap-x-3 gt:gap-y-1">
          <a :href="blockUrl(u.name)" class="gt:inline-flex gt:items-center gt:gap-1 gt-link">
            {{ u.blocks.length ? t('admin-block-change') : t('admin-block-add') }}<ExternalLink class="gt:size-icon-xs" aria-hidden="true" />
          </a>
          <a :href="blockLogUrl(u.name)" class="gt:inline-flex gt:items-center gt:gap-1 gt-link">
            {{ t('admin-block-log') }}<ExternalLink class="gt:size-icon-xs" aria-hidden="true" />
          </a>
        </div>
      </dd>
      <dt class="gt:text-fg-muted">{{ t('admin-user-comments') }}</dt>
      <dd class="gt-num gt:grid gt:grid-cols-[repeat(2,max-content)] gt:gap-x-4 gt:gap-y-0.5 gt:text-fg">
        <span>{{ t('admin-count-visible', num(data.counts.visible)) }}</span>
        <span>{{ t('admin-count-held', num(data.counts.held)) }}</span>
        <span>{{ t('admin-count-deleted', num(data.counts.deleted)) }}</span>
        <span v-if="data.counts.erased !== null">{{ t('admin-count-erased', num(data.counts.erased)) }}</span>
      </dd>
      <dt class="gt:text-fg-muted">{{ t('admin-user-reports') }}</dt>
      <dd class="gt-num gt:text-fg">
        <template v-if="reports.filed">
          <span class="gt:grid gt:grid-cols-[repeat(2,max-content)] gt:gap-x-4 gt:gap-y-0.5">
            <span>{{ t('admin-reports-filed', num(reports.filed)) }}</span>
            <span>{{ t('admin-reports-upheld', num(reports.upheld)) }}</span>
            <span>{{ t('admin-reports-dismissed', num(reports.dismissed)) }}</span>
            <span>{{ t('admin-reports-open', num(reports.open)) }}</span>
          </span>
          <span v-if="upheldRate !== null" class="gt:mt-0.5 gt:block gt:text-fg-muted">{{ t('admin-reports-rate', num(upheldRate)) }}</span>
          <a
            v-if="reports.open"
            :href="hub.href(queueRoute({ tab: 'reported', reporter: u.name }))"
            class="gt:mt-0.5 gt:inline-flex gt:items-center gt:gap-1 gt:font-medium gt-link"
            @click="theirReports"
          >
            {{ t('admin-reports-review', num(reports.open)) }}<ArrowRight class="gt-flip-rtl gt:size-icon-sm" aria-hidden="true" />
          </a>
        </template>
        <template v-else>{{ t('admin-reports-none') }}</template>
      </dd>
    </dl>

    <div>
      <h4 class="gt:mb-1.5 gt:text-meta gt:font-semibold gt:text-fg">{{ t('admin-user-moderation') }}</h4>
      <LogList :entries="data.moderation" :empty="t('admin-log-none-user')" />
    </div>

    <div>
      <h4 class="gt:mb-1.5 gt:text-meta gt:font-semibold gt:text-fg">{{ t('admin-user-recent') }}</h4>
      <!-- None here but `next`: the server skipped comments on pages the moderator cannot read, and
           All their comments pages on. -->
      <div v-if="!recent.length && !data.next" class="gt:text-meta gt:text-fg-muted">{{ t('admin-user-recent-none') }}</div>
      <ul v-else class="gt:flex gt:flex-col">
        <li v-for="item in recent" :key="item.comment.id" class="gt:border-t gt:border-border gt:py-2 gt:first:border-t-0 gt:first:pt-0">
          <div class="gt:flex gt:flex-wrap gt:items-center gt:gap-x-1.5 gt:text-meta gt:text-fg-muted">
            <a :href="commentPermalink(item.comment.id)" target="_blank" rel="noopener" class="gt:max-w-[12em] gt:truncate gt:text-fg gt:hover:underline" dir="auto">{{ item.page.title || t('admin-page-gone') }}</a>
            <span class="gt-num">#{{ item.comment.seq }}</span>
            <span aria-hidden="true">·</span>
            <RelativeTime :iso="item.comment.created" />
            <Badge v-if="item.comment.status === 'held'" tone="warning">{{ t('held') }}</Badge>
            <Badge v-else-if="item.comment.status === 'deleted'">{{ t('admin-status-deleted') }}</Badge>
            <Badge v-else-if="item.comment.status === 'erased'">{{ t('status-erased') }}</Badge>
          </div>
          <div class="gt:mt-0.5 gt:truncate gt:text-meta gt:text-fg" dir="auto">{{ excerpt(item.comment.segments) || t('erased-text') }}</div>
        </li>
      </ul>
      <a
        :href="hub.href(queueRoute({ tab: 'all', user: u.name, subject: 'user' }))"
        class="gt:mt-2 gt:inline-flex gt:items-center gt:gap-1 gt:text-meta gt:font-medium gt-link"
        @click="allComments"
      >
        {{ t('admin-user-all') }}<ArrowRight class="gt-flip-rtl gt:size-icon-sm" aria-hidden="true" />
      </a>
    </div>
  </section>
</template>
