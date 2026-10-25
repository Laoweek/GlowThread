import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { monogram, nameHue } from '@/lib/avatar'

// WCAG contrast of the monogram on its background for an HSL pair.
function hslToRgb(h: number, s: number, l: number): number[] {
  s /= 100
  l /= 100
  const k = (n: number) => (n + h / 30) % 12
  const a = s * Math.min(l, 1 - l)
  return [0, 8, 4].map((n) => l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1))))
}
const lum = (rgb: number[]) => {
  const [r, g, b] = rgb.map((c) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}
const contrast = (a: number[], b: number[]) => {
  const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p)
  return (x + 0.05) / (y + 0.05)
}

// The tone tokens, read from the token registry so the test follows any change.
const reg = JSON.parse(readFileSync(path.resolve('src', 'design', 'tokens.json'), 'utf8')) as { tokens: { name: string, light: string, night: string }[] }
function tones(mode: 'light' | 'night') {
  const get = (name: string) => parseFloat((reg.tokens.find((t) => t.name === 'avatar-' + name) as Record<string, string>)[mode])
  return { bgS: get('bg-saturation'), bgL: get('bg-lightness'), fgS: get('fg-saturation'), fgL: get('fg-lightness') }
}
const light = tones('light')
const dark = tones('night')

describe('nameHue', () => {
  it('is stable and in range', () => {
    expect(nameHue('Alice')).toBe(nameHue('Alice'))
    // Non-Latin names (CJK, emoji) hash into range too.
    for (const n of ['Alice', 'Bob', '张三', '203.0.113.7', 'a', '😀']) {
      const h = nameHue(n)
      expect(Number.isInteger(h) && h >= 0 && h < 360).toBe(true)
    }
  })
  it('spreads names over the colour wheel', () => {
    const hues = new Set<number>()
    for (let i = 0; i < 200; i++) hues.add(Math.floor(nameHue('User' + i) / 30))
    expect(hues.size).toBe(12)
  })
})

describe('avatar colours', () => {
  it.each([['light', light], ['dark', dark]])('meet 4.5:1 at every hue (%s)', (_, t) => {
    let worst = 99
    for (let h = 0; h < 360; h++) {
      worst = Math.min(worst, contrast(hslToRgb(h, t.fgS, t.fgL), hslToRgb(h, t.bgS, t.bgL)))
    }
    expect(worst).toBeGreaterThanOrEqual(4.5)
  })
})

describe('monogram', () => {
  it('takes the first character, upper-cased', () => {
    expect(monogram('alice')).toBe('A')
    // A CJK name has no case: its first character as is.
    expect(monogram('张三')).toBe('张')
    expect(monogram('😀smile')).toBe('😀')
    expect(monogram('  bob')).toBe('B')
  })
})
