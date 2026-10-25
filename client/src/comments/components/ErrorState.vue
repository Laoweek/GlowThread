<script setup lang="ts">
import { CloudOff, RotateCw } from '@lucide/vue'
import { Button } from '@/ui/button'
import { t } from '@/lib/i18n'

/** Loading failed: what happened and a retry. */
defineProps<{ message: string, busy?: boolean }>()
const emit = defineEmits<{ retry: [] }>()
</script>

<template>
  <div class="ext-glowthread-error gt:flex gt:flex-col gt:items-center gt:gap-3 gt:px-4 gt:py-10 gt:text-center" role="alert">
    <CloudOff class="gt:size-icon-2xl gt:text-fg-muted" aria-hidden="true" />
    <p class="gt:max-w-[28em] gt:text-body gt:text-fg-muted">{{ message }}</p>
    <Button variant="secondary" :disabled="busy" @click="emit('retry')">
      <RotateCw class="gt:size-icon" aria-hidden="true" />{{ t('retry') }}
    </Button>
  </div>
</template>
