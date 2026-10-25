<script setup lang="ts">
import { computed } from 'vue'
import type { Author } from './types'
import { userUrl } from '@/lib/wiki'
import { displayName } from '@/lib/names'
import { t } from '@/lib/i18n'

/**
 * An author's name. Registered users link to their user page; legacy anonymous comments
 * show the old IP address as plain text (a product decision); hidden users get a label.
 * `reveal` (moderators): characters that show nothing are written out, so lookalikes differ,
 * and in full on hover where a long name is cut.
 */
const props = defineProps<{ author: Author | null, reveal?: boolean }>()
const name = computed(() => (props.author && !props.author.hidden ? props.author.name : null))
const shown = computed(() => (name.value ? displayName(name.value, !!props.reveal) : name.value))
</script>

<template>
  <span v-if="!name" class="ext-glowthread-name gt:text-fg-muted">{{ t('user-hidden') }}</span>
  <span v-else-if="author && author.anon" class="ext-glowthread-name gt:text-fg" dir="auto">{{ shown }}</span>
  <a v-else :href="userUrl(name)" class="ext-glowthread-name gt:text-fg gt:hover:underline" dir="auto" :title="shown !== name ? shown || undefined : undefined">{{ shown }}</a>
</template>
