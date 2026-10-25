<script setup lang="ts">
import { computed, ref } from 'vue'
import type { Comment, ReportReason } from '@/shared/types'
import { RadioGroup, RadioGroupItem } from '@/ui/radio-group'
import { Textarea } from '@/ui/textarea'
import { Button } from '@/ui/button'
import { toast } from '@/ui/toast'
import { useStore } from '../context'
import { errorMessage } from '@/lib/errors'
import { codePointLength } from '@/lib/text'
import { t, num } from '@/lib/i18n'

/** The report form: one reason (required) and an optional note of up to 500 characters. */
const props = defineProps<{ comment: Comment, heading: boolean }>()
const emit = defineEmits<{ close: [] }>()
const store = useStore()
const reason = ref('')
const note = ref('')
const busy = ref(false)
const error = ref('')
const NOTE_MAX = 500
const reasons = computed<ReportReason[]>(() => (store.data.value ? store.data.value.config.reportReasons : []))
const noteLength = computed(() => codePointLength(note.value))

async function submit() {
  if (!reason.value) return
  busy.value = true
  error.value = ''
  try {
    const r = await store.report(props.comment, reason.value as ReportReason, note.value.trim())
    toast(r.already ? t('report-already') : t('report-done'))
    emit('close')
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <form class="ext-glowthread-report gt:flex gt:flex-col gt:gap-3" @submit.prevent="submit">
    <h3 v-if="heading" class="gt:text-body gt:font-semibold">{{ t('report-title') }}</h3>
    <RadioGroup v-model="reason" class="gt:flex gt:flex-col" :aria-label="t('report-reason')" :required="true">
      <RadioGroupItem v-for="r in reasons" :id="'gt-report-' + r" :key="r" :value="r">{{ t('reason-' + r) }}</RadioGroupItem>
    </RadioGroup>
    <div class="gt:flex gt:flex-col gt:gap-1.5">
      <label for="gt-report-note" class="gt:text-meta gt:text-fg-muted">{{ t('report-note') }}</label>
      <div class="gt:rounded-surface gt:border gt:border-border-control gt:bg-surface gt:focus-within:border-accent">
        <Textarea id="gt-report-note" v-model="note" :rows="2" :max-rows="5" class="gt:px-3 gt:py-2" />
      </div>
      <p v-if="noteLength > NOTE_MAX - 50" class="gt-num gt:text-meta" :class="noteLength > NOTE_MAX ? 'gt:text-danger' : 'gt:text-fg-muted'">
        {{ num(noteLength) }} / {{ num(NOTE_MAX) }}
      </p>
    </div>
    <p v-if="error" class="gt:text-meta gt:text-danger" role="alert">{{ error }}</p>
    <div class="gt:flex gt:justify-end gt:gap-2">
      <Button variant="ghost" @click="emit('close')">{{ t('cancel') }}</Button>
      <Button type="submit" variant="primary" :disabled="!reason || busy || noteLength > NOTE_MAX">{{ t('report-submit') }}</Button>
    </div>
  </form>
</template>
