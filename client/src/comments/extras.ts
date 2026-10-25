// The comment section's second module, ext.glowThread.app.extras: the parts only readers who
// can post, report or moderate use. Anonymous readers, most of the audience, never load it.
// Use these through lazy.ts: a static import from the first module would pull them back in.
export { default as Composer } from './components/Composer.vue'
export { default as ReportDialog } from './components/ReportDialog.vue'
export { default as ModTools } from './components/ModTools.vue'
export { default as ModQueue } from './components/ModQueue.vue'
export { default as ModNotes } from './components/ModNotes.vue'
export { default as BulkBar } from './components/BulkBar.vue'
export { Checkbox } from '@/ui/checkbox'
