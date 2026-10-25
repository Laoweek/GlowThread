// One Vite library build per app entry. scripts/build.mjs runs it once per folder
// src/<entry>/ that has a main.ts, with GT_ENTRY=<entry> and GT_OUT=<output name>.
// The output is a single CommonJS file plus one stylesheet for a ResourceLoader
// packageFiles module: resources/dist/<output name>.js and .css. Each import() adds a
// file of its own module, <output name>-<chunk>.js (scripts/vite-plugin-mw-chunks.mjs).
import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import mwScope from './scripts/vite-plugin-mw-scope.mjs'
import licenses from './scripts/vite-plugin-licenses.mjs'
import mwChunks from './scripts/vite-plugin-mw-chunks.mjs'
import rekaUseId from './scripts/vite-plugin-reka-useid.mjs'
import { CSS_TARGETS, JS_TARGET } from './scripts/targets.mjs'

const entry = process.env.GT_ENTRY || 'comments'
const out = process.env.GT_OUT || 'app'

export default defineConfig({
  plugins: [rekaUseId(), vue(), tailwindcss(), mwScope(), licenses({ out }), mwChunks({ out })],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
    // Reka UI and these apps use the Composition API only.
    __VUE_OPTIONS_API__: 'false',
    __VUE_PROD_DEVTOOLS__: 'false',
    __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
  },
  build: {
    target: JS_TARGET,
    cssTarget: CSS_TARGETS,
    cssMinify: 'lightningcss',
    outDir: fileURLToPath(new URL('../resources/dist', import.meta.url)),
    emptyOutDir: false,
    cssCodeSplit: false,
    // ResourceLoader's minifier only strips whitespace, so ship minified code.
    minify: true,
    reportCompressedSize: false,
    lib: {
      entry: fileURLToPath(new URL(`./src/${entry}/main.ts`, import.meta.url)),
      formats: ['cjs'],
      fileName: () => `${out}.js`,
      cssFileName: out,
    },
    rolldownOptions: {
      // An import() makes a second file (<output>-<name>.js, its own ResourceLoader module):
      // code it shares with the bundle stays in the bundle, which exports it.
      preserveEntrySignatures: 'allow-extension',
      output: { exports: 'named', chunkFileNames: `${out}-[name].js` },
    },
  },
})
