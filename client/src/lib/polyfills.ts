// Runtime APIs that Reka UI and VueUse call without a guard and that the browser floor
// lacks (Array#at: Chrome 92, Safari 15.4; Object.hasOwn: Chrome 93, Safari 15.4).
// Each is defined only when missing, with the standard behaviour, and not enumerable.
function define(target: object, name: string, value: unknown) {
  if (!(name in target)) {
    Object.defineProperty(target, name, { value, writable: true, configurable: true, enumerable: false })
  }
}

define(Array.prototype, 'at', function at(this: unknown[], index: number) {
  const n = Math.trunc(index) || 0
  const i = n < 0 ? this.length + n : n
  return i < 0 || i >= this.length ? undefined : this[i]
})

define(Object, 'hasOwn', function hasOwn(object: object, key: PropertyKey) {
  return Object.prototype.hasOwnProperty.call(object, key)
})
