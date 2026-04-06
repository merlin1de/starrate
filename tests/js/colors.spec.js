import { describe, it, expect } from 'vitest'
import { COLORS } from '../../src/utils/colors.js'

describe('COLORS Utility', () => {
  it('enthält genau 5 Farben', () => {
    expect(COLORS).toHaveLength(5)
  })

  it('jede Farbe hat key, label, shortcut, hex', () => {
    for (const c of COLORS) {
      expect(c).toHaveProperty('key')
      expect(c).toHaveProperty('label')
      expect(c).toHaveProperty('shortcut')
      expect(c).toHaveProperty('hex')
    }
  })

  it('Farb-Keys sind eindeutig', () => {
    const keys = COLORS.map(c => c.key)
    expect(new Set(keys).size).toBe(keys.length)
  })

  it('Shortcuts sind eindeutig', () => {
    const shortcuts = COLORS.map(c => c.shortcut)
    expect(new Set(shortcuts).size).toBe(shortcuts.length)
  })

  it('Hex-Werte haben gültiges Format', () => {
    for (const c of COLORS) {
      expect(c.hex).toMatch(/^#[0-9a-f]{6}$/i)
    }
  })

  it('enthält die erwarteten Farben in Reihenfolge', () => {
    expect(COLORS.map(c => c.key)).toEqual(['Red', 'Yellow', 'Green', 'Blue', 'Purple'])
  })

  it('Shortcuts 6–9 für die ersten 4 Farben, V für Purple', () => {
    expect(COLORS[0].shortcut).toBe('6')
    expect(COLORS[1].shortcut).toBe('7')
    expect(COLORS[2].shortcut).toBe('8')
    expect(COLORS[3].shortcut).toBe('9')
    expect(COLORS[4].shortcut).toBe('V')
  })
})
