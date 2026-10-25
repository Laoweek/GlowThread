// Browsers the shipped bundles must work in. Many readers on phones still use in-app
// Android WebViews on Chrome 90 to 110, so the floor is far below Tailwind v4's own
// (Chrome 111, Safari 16.4, Firefox 128).
// Change these together: the JS target, Vite's CSS target and the final Lightning CSS pass.

/** esbuild-style names, for Vite's build.cssTarget. */
export const CSS_TARGETS = ['chrome87', 'edge88', 'firefox78', 'safari14', 'ios14']

/** Lightning CSS targets (major << 16). */
export const LIGHTNING_TARGETS = {
  chrome: 87 << 16,
  edge: 88 << 16,
  firefox: 78 << 16,
  safari: 14 << 16,
  ios_saf: 14 << 16,
  android: 87 << 16,
}

/** Syntax level of the JavaScript bundles (ResourceLoader's minifier knows ES2017). */
export const JS_TARGET = 'es2017'
