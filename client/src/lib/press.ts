// A click or tap elsewhere blurs a focused field before its click event: on mousedown with a
// mouse, after the touch ends with a finger. A field that collapses on blur would move
// everything below it while the press is still in progress, and the click would land beside
// the pressed button (the ⋯ menu opens on click). afterPress() runs such a layout change once
// the press is over: after its click, or shortly after a press that ends without one.

let lastPointer = 0
const note = () => {
  lastPointer = Date.now()
}
window.addEventListener('pointerdown', note, true)
window.addEventListener('pointerup', note, true)

/** Runs `fn` now, or after the click of a press in progress (or just finished, on touch). */
export function afterPress(fn: () => void): void {
  if (Date.now() - lastPointer > 1000) {
    fn()
    return
  }
  let timer = 0
  const done = () => {
    window.removeEventListener('click', done, true)
    window.removeEventListener('pointerup', soon, true)
    window.clearTimeout(timer)
    // After the click's own handlers.
    window.setTimeout(fn, 0)
  }
  // A press that ends without a click (a drag, a tap that scrolls).
  const soon = () => {
    window.clearTimeout(timer)
    timer = window.setTimeout(done, 400)
  }
  timer = window.setTimeout(done, 1000)
  window.addEventListener('click', done, true)
  window.addEventListener('pointerup', soon, true)
}
