<script setup lang="ts">
import { LoaderCircle } from '@lucide/vue'
</script>

<template>
  <!-- Only inside controls (a busy button), never in place of content. -->
  <LoaderCircle class="gt-spin gt:size-icon" aria-hidden="true" />
</template>
