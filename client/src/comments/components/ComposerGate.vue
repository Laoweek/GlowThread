<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Ban, Lock, LogIn, Info } from '@lucide/vue'
import type { PostBlockedReason } from '@/shared/types'
import { Avatar } from '@/ui/avatar'
import { Button } from '@/ui/button'
import { actionGet } from '@/lib/api'
import { blockListUrl, loginUrl } from '@/lib/wiki'
import { t } from '@/lib/i18n'

/**
 * In place of the composer when the reader cannot post: logged out (Log in to join the conversation),
 * blocked sitewide or from this page (with the reason and a link), page locked, or no right.
 */
const props = defineProps<{ reason: PostBlockedReason }>()
const block = ref<{ reason: string, partial: boolean, expiry: string, id: number } | null>(null)
const user = mw.config.get('wgUserName') as string | null

onMounted(() => {
  if (props.reason !== 'blocked') return
  // Block details come from core: the list response only says "blocked".
  actionGet<{ query: { userinfo: { blockid?: number, blockreason?: string, blockpartial?: boolean, blockexpiry?: string } } }>({
    action: 'query',
    meta: 'userinfo',
    uiprop: 'blockinfo',
  })
    .then((r) => {
      const u = r.query.userinfo
      block.value = { reason: u.blockreason || '', partial: !!u.blockpartial, expiry: u.blockexpiry || '', id: u.blockid || 0 }
    })
    .catch(() => undefined)
})

const icon = computed(() => ({ login: LogIn, blocked: Ban, locked: Lock }[props.reason as string] || Info))
const text = computed(() => {
  switch (props.reason) {
    case 'login': return t('gate-login')
    case 'blocked': return block.value && block.value.partial ? t('gate-blocked-page') : t('gate-blocked')
    case 'locked': return t('gate-locked')
    case 'disabled': return t('gate-disabled')
    case 'readonly': return t('gate-readonly')
    case 'ineligible': return t('gate-ineligible')
    default: return t('gate-rights')
  }
})
</script>

<template>
  <div class="ext-glowthread-gate gt:flex gt:items-start gt:gap-(--gt-v-avatar-gap)">
    <Avatar :generic="true" class="gt:mt-0.5" />
    <div
      class="gt:flex gt:min-h-10 gt:min-w-0 gt:flex-1 gt:flex-wrap gt:items-center gt:gap-x-3 gt:gap-y-2 gt:rounded-surface gt:bg-surface-subtle gt:px-3 gt:py-2"
      :role="reason === 'login' ? undefined : 'note'"
    >
      <component :is="icon" class="gt:size-icon gt:shrink-0 gt:text-fg-muted" aria-hidden="true" />
      <div class="gt:min-w-0 gt:flex-1 gt:text-body gt:text-fg-muted">
        <!-- The state itself reads first; the login prompt stays quiet like a placeholder. -->
        <p :class="reason === 'login' ? '' : 'gt:text-fg'">{{ text }}</p>
        <p v-if="block && block.reason" class="gt:mt-0.5 gt:text-meta" dir="auto">{{ t('gate-block-reason', block.reason) }}</p>
        <a v-if="reason === 'blocked' && user" :href="blockListUrl(block && block.id ? '#' + block.id : user)" class="gt-hit gt:mt-0.5 gt:inline-block gt:text-meta gt:font-medium gt:text-accent-text gt:hover:underline">
          {{ t('gate-block-details') }}
        </a>
      </div>
      <Button v-if="reason === 'login'" as="a" :href="loginUrl()" variant="primary" size="sm">{{ t('login') }}</Button>
    </div>
  </div>
</template>
