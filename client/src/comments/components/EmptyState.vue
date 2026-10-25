<script setup lang="ts">
import { MessageCircle } from '@lucide/vue'
import { t } from '@/lib/i18n'

/** No comments yet: invite the first one (the composer sits right above). */
defineProps<{ canPost: boolean }>()
</script>

<template>
  <div class="ext-glowthread-empty gt:flex gt:flex-col gt:items-center gt:gap-2 gt:px-4 gt:py-12 gt:text-center">
    <MessageCircle class="gt:size-icon-2xl gt:text-fg-muted" aria-hidden="true" />
    <p class="gt:text-body gt:font-medium gt:text-fg">{{ t('empty-title') }}</p>
    <p class="gt:text-meta gt:text-fg-muted">{{ canPost ? t('empty-invite') : t('empty-read') }}</p>
  </div>
</template>
