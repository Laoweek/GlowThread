<script setup lang="ts">
import { computed } from 'vue'
import { Lock } from '@lucide/vue'
import type { SettingInfo } from '@/shared/types'
import { Checkbox } from '@/ui/checkbox'
import { t, num } from '@/lib/i18n'
import { UNITS, invalidValue, settingKey } from '../settings'
import type { SettingValue } from '../settings'
import { duration } from '../format'

/**
 * One on-wiki setting: what it does, its value with the unit, the range, the default, and
 * whether the operator locked it in LocalSettings. Read-only without glowthread-configure.
 */
const props = defineProps<{ name: string, info: SettingInfo, value: SettingValue, readonly: boolean }>()
const emit = defineEmits<{ update: [value: SettingValue] }>()
const id = computed(() => 'gt-setting-' + props.name)
const locked = computed(() => !props.info.editable)
const off = computed(() => props.readonly || locked.value)
const changed = computed(() => JSON.stringify(props.value) !== JSON.stringify(props.info.value))
const problem = computed(() => invalidValue(props.info, props.value))
const unit = computed(() => UNITS[props.name])
const key = computed(() => settingKey(props.name))

function defaultText(): string {
  const d = props.info.default
  if (Array.isArray(d)) return d.map((r) => t('reason-' + r)).join(t('list-separator'))
  if (typeof d === 'boolean') return d ? t('admin-on') : t('admin-off')
  return unit.value ? t('admin-value-' + unit.value, num(d)) : num(d)
}

function onNumber(e: Event) {
  const raw = (e.target as HTMLInputElement).value
  emit('update', raw === '' ? NaN : Number(raw))
}

function toggleReason(reason: string, on: boolean) {
  const list = Array.isArray(props.value) ? props.value.slice() : []
  const i = list.indexOf(reason)
  if (on && i < 0) list.push(reason)
  if (!on && i >= 0) list.splice(i, 1)
  emit('update', (props.info.options || []).filter((o) => list.indexOf(o) >= 0))
}
</script>

<template>
  <!-- Locked in LocalSettings: greyed out, so it reads as fixed before anyone tries it. -->
  <div class="gt:flex gt:flex-col gt:gap-1.5 gt:py-3" :class="locked && 'gt:opacity-60'">
    <div class="gt:flex gt:flex-wrap gt:items-baseline gt:gap-x-2">
      <label v-if="info.type !== 'list'" :for="id" class="gt:text-body gt:font-medium gt:text-fg">{{ t('admin-setting-' + key) }}</label>
      <div v-else :id="id" class="gt:text-body gt:font-medium gt:text-fg">{{ t('admin-setting-' + key) }}</div>
      <span v-if="changed" class="gt:text-meta gt:font-medium gt:text-accent-text">{{ t('admin-setting-changed') }}</span>
      <span v-if="locked" class="gt:inline-flex gt:items-center gt:gap-1 gt:text-meta gt:text-fg-muted"><Lock class="gt:size-icon-xs" aria-hidden="true" />{{ t('admin-setting-locked') }}</span>
    </div>
    <div :id="id + '-help'" class="gt:max-w-[40em] gt:text-meta gt:text-fg-muted">{{ t('admin-setting-' + key + '-help') }}</div>

    <div v-if="info.type === 'int'" class="gt:flex gt:flex-wrap gt:items-center gt:gap-2">
      <input
        :id="id"
        type="number"
        inputmode="numeric"
        step="1"
        :min="info.min"
        :max="info.max"
        :value="Number.isNaN(value as number) ? '' : value"
        :disabled="off"
        :aria-describedby="id + '-help ' + id + '-range'"
        :aria-invalid="problem ? 'true' : undefined"
        class="gt-num gt:h-[2.75rem] gt:w-28 gt:sm:h-8 gt:rounded-control gt:border gt:bg-surface gt:px-2.5 gt:text-body gt:text-fg gt:focus:border-accent gt:disabled:bg-surface-subtle gt:disabled:text-fg-muted"
        :class="problem ? 'gt:border-danger' : 'gt:border-border-control'"
        @input="onNumber"
      >
      <span v-if="unit" class="gt:text-body gt:text-fg-muted">{{ t('admin-unit-' + unit) }}</span>
      <span v-if="name === 'duplicateWindow' && !problem" class="gt:text-meta gt:text-fg-muted">= {{ duration(value as number) }}</span>
      <span :id="id + '-range'" class="gt-num gt:text-meta gt:text-fg-muted">
        {{ info.minOn !== undefined ? t('admin-setting-range-off', num(info.min || 0), num(info.minOn), num(info.max || 0)) : t('admin-setting-range', num(info.min || 0), num(info.max || 0)) }} · {{ t('admin-setting-default', defaultText()) }}
      </span>
    </div>

    <label v-else-if="info.type === 'bool'" class="gt:flex gt:min-h-[2.75rem] gt:items-center gt:gap-2 gt:text-body gt:sm:min-h-8">
      <Checkbox :id="id" :model-value="!!value" :disabled="off" @update:model-value="emit('update', !!$event)" />
      {{ value ? t('admin-on') : t('admin-off') }}
      <span class="gt:text-meta gt:text-fg-muted">· {{ t('admin-setting-default', defaultText()) }}</span>
    </label>

    <div v-else class="gt:flex gt:flex-col gt:gap-1" role="group" :aria-labelledby="id" :aria-describedby="id + '-help'">
      <label v-for="o in info.options" :key="o" class="gt:flex gt:min-h-[2.75rem] gt:items-center gt:gap-2 gt:text-body gt:sm:min-h-8">
        <Checkbox
          :model-value="Array.isArray(value) && value.indexOf(o) >= 0"
          :disabled="off"
          @update:model-value="toggleReason(o, !!$event)"
        />
        {{ t('reason-' + o) }}
      </label>
    </div>

    <div v-if="problem" class="gt:text-meta gt:text-danger" role="alert">
      {{ problem === 'list' ? t('admin-setting-list-min') : info.minOn !== undefined ? t('admin-setting-range-off-error', num(info.min || 0), num(info.minOn), num(info.max || 0)) : t('admin-setting-range-error', num(info.min || 0), num(info.max || 0)) }}
    </div>
  </div>
</template>
