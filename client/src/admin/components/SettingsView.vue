<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { ExternalLink } from '@lucide/vue'
import { Button } from '@/ui/button'
import { Skeleton } from '@/ui/skeleton'
import { Spinner } from '@/ui/spinner'
import { toast } from '@/ui/toast'
import { errorMessage } from '../errors'
import { logUrl } from '@/lib/wiki'
import { t, num } from '@/lib/i18n'
import { useHub } from '../context'
import { SECTIONS, UNITS, settingKey } from '../settings'
import type { SettingValue } from '../settings'
import { duration } from '../format'
import { isImeEnter } from '../keyboard'
import SettingField from './SettingField.vue'
import FilterEditor from './FilterEditor.vue'
import Notice from './Notice.vue'

/**
 * Settings, explained in place and saved with a reason: the changes are listed before
 * saving, and the result links to its entry in Special:Log/glowthread. Rate limits are
 * shown for reference (they live in LocalSettings). The word filter has its own editor.
 */
const hub = useHub()
const s = hub.settings
const st = s.state
const data = computed(() => st.data)
const readonly = computed(() => !data.value || !data.value.canConfigure)
const reviewing = ref(false)
const reason = ref('')
const reasonMissing = ref(false)
const saveError = ref<unknown>(null)
const reasonInput = ref<HTMLInputElement | null>(null)
// $wgRateLimits groups with a name in words; others (user groups) show their key only.
const RATE_GROUPS = ['user', 'newbie', 'ip', 'subnet', 'ip-all', 'subnet-all', 'anon', 'user-global']
function groupLabel(group: string): string {
  return RATE_GROUPS.indexOf(group) >= 0 ? t('admin-rate-group-' + group) : group
}
const RATE_KEYS = ['glowthread-post', 'glowthread-react', 'glowthread-report', 'glowthread-moderate', 'glowthread-erase', 'glowthread-configure']

function draftOf(name: string): SettingValue {
  return st.draft[name]
}

function show(name: string, value: unknown): string {
  if (Array.isArray(value)) return value.map((r) => t('reason-' + r)).join(t('list-separator')) || '∅'
  if (typeof value === 'boolean') return value ? t('admin-on') : t('admin-off')
  if (name === 'duplicateWindow') return duration(value as number)
  return UNITS[name] ? t('admin-value-' + UNITS[name], num(value as number)) : num(value as number)
}

function review() {
  reviewing.value = true
  saveError.value = null
  nextTick(() => reasonInput.value && reasonInput.value.focus())
}

function discard() {
  s.resetDraft()
  reviewing.value = false
  reason.value = ''
}

function onEnter(e: KeyboardEvent) {
  if (isImeEnter(e)) return
  e.preventDefault()
  save()
}

async function save() {
  // Enter reaches here too: the same checks as the disabled button.
  if (st.saving || hub.blocked.value || Object.keys(s.problems.value).length) return
  if (!reason.value.trim()) {
    reasonMissing.value = true
    if (reasonInput.value) reasonInput.value.focus()
    return
  }
  saveError.value = null
  try {
    const r = await s.save(reason.value.trim())
    reviewing.value = false
    reason.value = ''
    toast(t('admin-settings-saved', r.changed.length))
  } catch (e) {
    saveError.value = e
  }
}

const savedLog = computed(() => (st.saved && st.saved.logId
  ? logUrl({ logid: st.saved.logId })
  : logUrl({ type: 'glowthread', page: 'Special:GlowThread/settings' })))
</script>

<template>
  <div class="ext-glowthread-settings gt:flex gt:max-w-[760px] gt:flex-col gt:gap-8">
    <header>
      <h2 class="gt:text-[calc(var(--gt-v-font-size-title)*1.125)] gt:font-semibold gt:text-fg">{{ t('admin-nav-settings') }}</h2>
      <div class="gt:mt-1 gt:text-meta gt:text-fg-muted">{{ t('admin-settings-intro') }}</div>
    </header>

    <div v-if="st.status === 'loading' || st.status === 'idle'" class="ext-glowthread-loading gt:flex gt:flex-col gt:gap-3" aria-hidden="true">
      <Skeleton v-for="i in 4" :key="i" class="gt:h-16 gt:w-full" />
    </div>
    <Notice v-else-if="st.status === 'error'" tone="danger" :action="t('retry')" @action="s.load()">{{ errorMessage(st.error) }}</Notice>

    <template v-else-if="data">
      <Notice v-if="readonly">{{ t('admin-settings-readonly') }}</Notice>
      <Notice v-else-if="hub.blocked.value" tone="danger">{{ t('admin-blocked-settings') }}</Notice>

      <Notice v-if="st.saved" tone="neutral">
        {{ t('admin-settings-saved', st.saved.changed.length) }}
        <a :href="savedLog" class="gt:ml-1 gt:inline-flex gt:items-center gt:gap-1 gt:font-medium gt-link">
          {{ t('admin-settings-log') }}<ExternalLink class="gt:size-icon-xs" aria-hidden="true" />
        </a>
      </Notice>

      <section v-for="sec in SECTIONS" :key="sec.id" :aria-labelledby="'gt-sec-' + sec.id">
        <h3 :id="'gt-sec-' + sec.id" class="gt:border-b gt:border-border gt:pb-2 gt:text-title gt:font-semibold gt:text-fg">{{ t('admin-settings-' + sec.id) }}</h3>
        <div v-if="sec.id === 'gate'" class="gt:mt-3 gt:rounded-control gt:bg-surface-subtle gt:px-3 gt:py-2 gt:text-meta gt:text-fg" aria-live="polite">
          {{ (draftOf('newAccountHours') as number) > 0
            ? t('admin-gate-summary', num(draftOf('newAccountHours') as number), num(draftOf('newAccountApproved') as number))
            : t('admin-gate-off') }}
        </div>
        <div class="gt:mt-1">
          <template v-for="name in sec.settings" :key="name">
            <SettingField
              v-if="data.settings[name]"
              :name="name"
              :info="data.settings[name]"
              :value="st.draft[name]"
              :readonly="readonly"
              @update="st.draft[name] = $event"
            />
          </template>
        </div>
      </section>

      <section aria-labelledby="gt-sec-rate">
        <h3 id="gt-sec-rate" class="gt:border-b gt:border-border gt:pb-2 gt:text-title gt:font-semibold gt:text-fg">{{ t('admin-settings-rate') }}</h3>
        <div class="gt:mt-3 gt:max-w-[40em] gt:text-meta gt:text-fg-muted">{{ t('admin-settings-rate-help') }}</div>
        <table v-if="data.rateLimits && data.rateLimits.length" class="gt:mt-2 gt:w-full gt:text-meta">
          <thead>
            <tr class="gt:text-left gt:text-fg-muted">
              <th scope="col" class="gt:py-1.5 gt:pr-3 gt:font-medium">{{ t('admin-rate-key') }}</th>
              <th scope="col" class="gt:py-1.5 gt:font-medium">{{ t('admin-rate-limits') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in data.rateLimits" :key="r.key" class="gt:border-t gt:border-border">
              <td class="gt:py-1.5 gt:pr-3 gt:align-top">
                <span class="gt:text-fg">{{ t('admin-rate-' + r.key) }}</span>
                <code class="gt-code gt:block gt:text-fg-muted">{{ r.key }}</code>
                <span v-if="r.canBypass === false" class="gt:block gt:text-warning">{{ t('admin-rate-no-bypass') }}</span>
              </td>
              <td class="gt-num gt:py-1.5 gt:text-fg">
                <!-- Who the limit counts (in words), then the $wgRateLimits key it comes from. -->
                <span v-for="(v, group) in r.limits" :key="group" class="gt:flex gt:flex-wrap gt:items-baseline gt:gap-x-2">
                  <span>{{ t('admin-rate-value', groupLabel(String(group)), num(v[0]), duration(v[1])) }}</span>
                  <code class="gt-code gt:text-fg-muted">{{ group }}</code>
                </span>
              </td>
            </tr>
          </tbody>
        </table>
        <ul v-else class="gt:mt-2 gt:flex gt:flex-col gt:gap-1 gt:text-meta">
          <li v-for="k in RATE_KEYS" :key="k" class="gt:flex gt:flex-wrap gt:gap-x-2">
            <code class="gt-code gt:text-fg">{{ k }}</code><span class="gt:text-fg-muted">{{ t('admin-rate-' + k) }}</span>
          </li>
        </ul>
      </section>

      <FilterEditor />

      <!-- Unsaved changes: listed, with a reason, before anything is saved. -->
      <div
        v-if="s.changes.value.length && !readonly"
        class="gt-sticky-bar gt:sticky gt:bottom-[72px] gt:z-10 gt:flex gt:flex-col gt:gap-3 gt:rounded-surface gt:border gt:border-overlay-border gt:bg-surface gt:p-3 gt:shadow-overlay gt:sm:bottom-4"
        role="region"
        :aria-label="t('admin-settings-pending', s.changes.value.length)"
      >
        <template v-if="reviewing">
          <div class="gt:text-body gt:font-medium">{{ t('admin-settings-review') }}</div>
          <ul class="gt:flex gt:flex-col gt:gap-1 gt:text-meta">
            <li v-for="c in s.changes.value" :key="c.name" class="gt:flex gt:flex-wrap gt:gap-x-2">
              <span class="gt:text-fg">{{ t('admin-setting-' + settingKey(c.name)) }}</span>
              <span class="gt-num gt:text-fg-muted"><del>{{ show(c.name, c.old) }}</del> → <ins class="gt:font-medium gt:text-fg gt:no-underline">{{ show(c.name, c.new) }}</ins></span>
            </li>
          </ul>
          <label class="gt:flex gt:flex-col gt:gap-1 gt:text-meta gt:text-fg-muted">
            {{ t('admin-reason-required') }}
            <input
              ref="reasonInput"
              v-model="reason"
              type="text"
              maxlength="500"
              :aria-invalid="reasonMissing && !reason.trim() ? 'true' : undefined"
              class="gt:h-8 gt:w-full gt:rounded-control gt:border gt:bg-surface gt:px-2.5 gt:text-body gt:text-fg gt:focus:border-accent"
              :class="reasonMissing && !reason.trim() ? 'gt:border-danger' : 'gt:border-border-control'"
              :placeholder="t('admin-reason-placeholder')"
              @keydown.enter="onEnter"
            >
          </label>
          <div v-if="reasonMissing && !reason.trim()" class="gt:text-meta gt:text-danger" role="alert">{{ t('admin-reason-missing') }}</div>
          <div v-if="saveError" class="gt:text-meta gt:text-danger" role="alert">{{ errorMessage(saveError) }}</div>
          <div class="gt:flex gt:justify-end gt:gap-2">
            <Button size="sm" variant="ghost" @click="reviewing = false">{{ t('admin-settings-back') }}</Button>
            <Button size="sm" variant="primary" :disabled="st.saving || hub.blocked.value || Object.keys(s.problems.value).length > 0" @click="save">
              <Spinner v-if="st.saving" />{{ t('admin-settings-save') }}
            </Button>
          </div>
        </template>
        <div v-else class="gt:flex gt:flex-wrap gt:items-center gt:gap-2">
          <div class="gt-num gt:mr-auto gt:text-body">{{ t('admin-settings-pending', s.changes.value.length) }}</div>
          <Button size="sm" variant="ghost" @click="discard">{{ t('admin-settings-discard') }}</Button>
          <Button size="sm" variant="primary" :disabled="Object.keys(s.problems.value).length > 0" @click="review">{{ t('admin-settings-check') }}</Button>
        </div>
      </div>
    </template>
  </div>
</template>
