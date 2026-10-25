<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { ExternalLink } from '@lucide/vue'
import { Button } from '@/ui/button'
import { Skeleton } from '@/ui/skeleton'
import { Spinner } from '@/ui/spinner'
import { toast } from '@/ui/toast'
import { errorMessage } from '../errors'
import { diffUrl, pageUrl } from '@/lib/wiki'
import { t, num } from '@/lib/i18n'
import { useHub } from '../context'
import Notice from './Notice.vue'
import { isImeEnter } from '../keyboard'

/**
 * The word filter (MediaWiki:GlowThread-filter): an editor with line numbers where the
 * server flags invalid lines as you type, a test box that shows which line a sample
 * comment matches, and saving through the edit API with a summary. Nothing is saved
 * until Save rules.
 */
const hub = useHub()
const s = hub.settings
const f = s.state.filter
const test = s.state.test
const page = computed(() => (s.state.data ? s.state.data.filter.page : 'MediaWiki:GlowThread-filter'))
const canEdit = computed(() => !!(f.page && f.page.canEdit) && !hub.blocked.value)
const canTest = computed(() => !!(s.state.data && s.state.data.canConfigure))
const lines = computed(() => Math.max(1, f.draft.split('\n').length))
const invalidLines = computed(() => {
  const out: Record<number, string> = {}
  for (const r of f.invalid) out[r.line] = r.error
  return out
})
const matchedLines = computed(() => {
  const out: Record<number, true> = {}
  if (test.result) for (const m of test.result.matches) out[m.line] = true
  return out
})
const summary = ref('')
const summaryMissing = ref(false)
const saveError = ref<unknown>(null)

// Keep the line numbers beside the text when it scrolls.
const gutter = ref<HTMLElement | null>(null)
function syncScroll(e: Event) {
  if (gutter.value) gutter.value.scrollTop = (e.target as HTMLTextAreaElement).scrollTop
}

// The server checks the draft and runs the test text through it, 600 ms after typing stops
// (each check counts against the glowthread-configure rate limit, which sysops cannot skip).
let timer = 0
watch(() => [f.draft, test.text], () => {
  if (!canTest.value || f.status !== 'ready') return
  window.clearTimeout(timer)
  timer = window.setTimeout(() => s.check(), 600)
})
watch(() => f.status, (status) => {
  if (status === 'ready' && canTest.value) s.check()
})
onBeforeUnmount(() => window.clearTimeout(timer))

function onEnter(e: KeyboardEvent) {
  if (isImeEnter(e)) return
  e.preventDefault()
  save()
}

async function save() {
  if (f.saving) return
  if (!summary.value.trim()) {
    summaryMissing.value = true
    return
  }
  saveError.value = null
  try {
    const r = await s.saveFilter(summary.value.trim())
    summary.value = ''
    summaryMissing.value = false
    toast(r.nochange ? t('admin-filter-nochange') : t('admin-filter-saved'))
  } catch (e) {
    saveError.value = e
  }
}

function discard() {
  // Read the page again: after an edit conflict the copy on screen is out of date.
  saveError.value = null
  s.loadFilter()
}
</script>

<template>
  <section aria-labelledby="gt-sec-filter" class="ext-glowthread-filter-editor gt:flex gt:flex-col gt:gap-3">
    <div>
      <h3 id="gt-sec-filter" class="gt:border-b gt:border-border gt:pb-2 gt:text-title gt:font-semibold gt:text-fg">{{ t('admin-filter-title') }}</h3>
      <div class="gt:mt-3 gt:max-w-[44em] gt:text-meta gt:text-fg-muted">{{ t('admin-filter-help') }}</div>
      <div class="gt:mt-1 gt:flex gt:flex-wrap gt:gap-x-3 gt:text-meta">
        <span class="gt-num gt:text-fg-muted">{{ t('admin-filter-rules', num(f.rules)) }}</span>
        <a :href="pageUrl(page)" class="gt:inline-flex gt:items-center gt:gap-1 gt-link">
          {{ page }}<ExternalLink class="gt:size-icon-xs" aria-hidden="true" />
        </a>
      </div>
    </div>

    <Skeleton v-if="f.status === 'loading' || f.status === 'idle'" class="gt:h-48 gt:w-full" />
    <Notice v-else-if="f.status === 'error'" tone="danger" :action="t('retry')" @action="s.loadFilter()">{{ errorMessage(f.error) }}</Notice>
    <template v-else>
      <Notice v-if="f.page && !f.page.canEdit">{{ t('admin-filter-readonly') }}</Notice>

      <div class="gt:flex gt:overflow-hidden gt:rounded-control gt:border gt:border-border-control gt:bg-surface gt:focus-within:border-accent">
        <div
          ref="gutter"
          class="gt-code gt-num gt:w-11 gt:shrink-0 gt:overflow-hidden gt:border-r gt:border-border gt:bg-surface-subtle gt:py-2 gt:text-right gt:select-none"
          aria-hidden="true"
        >
          <div
            v-for="n in lines"
            :key="n"
            class="gt:pr-2"
            :class="invalidLines[n] ? 'gt:bg-danger-subtle gt:font-medium gt:text-danger' : matchedLines[n] ? 'gt:bg-accent-subtle gt:font-medium gt:text-accent-text' : 'gt:text-fg-subtle'"
          >
            {{ n }}
          </div>
        </div>
        <textarea
          v-model="f.draft"
          rows="12"
          wrap="off"
          spellcheck="false"
          autocapitalize="off"
          :readonly="!canEdit"
          :aria-label="t('admin-filter-editor')"
          aria-describedby="gt-filter-invalid"
          class="gt-code gt-scroll gt:block gt:min-h-48 gt:w-full gt:resize-y gt:overflow-x-auto gt:bg-transparent gt:px-3 gt:py-2 gt:whitespace-pre gt:text-fg gt:focus-visible:outline-offset-[calc(var(--gt-v-focus-ring-width)*-1)]"
          @scroll="syncScroll"
        />
      </div>

      <ul id="gt-filter-invalid" class="gt:flex gt:flex-col gt:gap-1 gt:text-meta" aria-live="polite">
        <li v-for="r in f.invalid" :key="r.line" class="gt:flex gt:flex-wrap gt:gap-x-2 gt:text-danger">
          <span class="gt-num gt:font-medium">{{ t('admin-filter-line', num(r.line)) }}</span>
          <code class="gt-code gt:text-fg" dir="ltr">{{ r.rule }}</code>
          <span>{{ r.error }}</span>
        </li>
      </ul>

      <div v-if="canEdit && s.filterDirty.value" class="gt:flex gt:flex-wrap gt:items-start gt:gap-2">
        <div class="gt:flex gt:min-w-[14em] gt:flex-1 gt:flex-col gt:gap-1">
          <input
            v-model="summary"
            type="text"
            maxlength="500"
            :aria-label="t('admin-filter-summary')"
            :aria-invalid="summaryMissing && !summary.trim() ? 'true' : undefined"
            :placeholder="t('admin-filter-summary')"
            class="gt:h-8 gt:w-full gt:rounded-control gt:border gt:bg-surface gt:px-2.5 gt:text-body gt:text-fg gt:focus:border-accent"
            :class="summaryMissing && !summary.trim() ? 'gt:border-danger' : 'gt:border-border-control'"
            @keydown.enter="onEnter"
          >
          <div v-if="summaryMissing && !summary.trim()" class="gt:text-meta gt:text-danger" role="alert">{{ t('admin-reason-missing') }}</div>
          <div v-if="saveError" class="gt:text-meta gt:text-danger" role="alert">{{ errorMessage(saveError) }}</div>
        </div>
        <Button size="sm" variant="ghost" @click="discard">{{ t('admin-settings-discard') }}</Button>
        <Button size="sm" variant="primary" :disabled="f.saving" @click="save"><Spinner v-if="f.saving" />{{ t('admin-filter-save') }}</Button>
      </div>
      <div v-if="f.savedRev && !s.filterDirty.value" class="gt:text-meta gt:text-fg-muted">
        {{ t('admin-filter-saved') }}
        <a :href="diffUrl(f.savedRev)" class="gt:inline-flex gt:items-center gt:gap-1 gt-link">{{ t('admin-filter-diff') }}<ExternalLink class="gt:size-icon-xs" aria-hidden="true" /></a>
      </div>

      <div v-if="canTest" class="gt:flex gt:flex-col gt:gap-1.5 gt:rounded-surface gt:bg-surface-subtle gt:p-3">
        <label for="gt-filter-test" class="gt:text-meta gt:font-medium gt:text-fg">{{ t('admin-filter-test') }}</label>
        <textarea
          id="gt-filter-test"
          v-model="test.text"
          rows="2"
          class="gt:block gt:w-full gt:resize-y gt:rounded-control gt:border gt:border-border-control gt:bg-surface gt:px-2.5 gt:py-1.5 gt:text-body gt:text-fg gt:focus:border-accent"
          :placeholder="t('admin-filter-test-placeholder')"
        />
        <div class="gt:min-h-5 gt:text-meta" aria-live="polite">
          <span v-if="test.running" class="gt:text-fg-muted">{{ t('admin-filter-testing') }}</span>
          <span v-else-if="test.error" class="gt:text-danger">{{ errorMessage(test.error) }}</span>
          <template v-else-if="test.result">
            <span v-if="test.result.matches.length" class="gt:font-medium gt:text-warning">
              {{ t('admin-filter-test-held', test.result.matches.map((m) => t('admin-rule-line', m.line, m.rule)).join(t('list-separator'))) }}
            </span>
            <span v-else-if="test.result.errors.length" class="gt:font-medium gt:text-warning">
              {{ t('admin-filter-test-error', test.result.errors.map((m) => num(m.line)).join(t('list-separator'))) }}
            </span>
            <span v-else class="gt:text-success">{{ t('admin-filter-test-pass') }}</span>
          </template>
          <span v-else class="gt:text-fg-muted">{{ t('admin-filter-test-hint') }}</span>
        </div>
      </div>
    </template>
  </section>
</template>
