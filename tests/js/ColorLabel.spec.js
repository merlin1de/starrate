import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ColorLabel from '../../src/components/ColorLabel.vue'

describe('ColorLabel', () => {
  const factory = (props = {}) => mount(ColorLabel, {
    props: { modelValue: null, interactive: true, ...props },
  })

  // ── Rendering ─────────────────────────────────────────────────────────────

  it('rendert 5 Farbpunkte', () => {
    const w = factory()
    expect(w.findAll('.sr-color-label__dot')).toHaveLength(5)
  })

  it('markiert den aktiven Farbpunkt', () => {
    const w = factory({ modelValue: 'Green' })
    const dots = w.findAll('.sr-color-label__dot')
    const active = dots.filter(d => d.classes().includes('sr-color-label__dot--active'))
    expect(active).toHaveLength(1)
    expect(active[0].classes()).toContain('sr-color-label__dot--green')
  })

  it('kein Farbpunkt aktiv bei modelValue=null', () => {
    const w = factory({ modelValue: null })
    expect(w.findAll('.sr-color-label__dot--active')).toHaveLength(0)
  })

  it('zeigt Clear-Button im interaktiven Modus', () => {
    const w = factory({ interactive: true })
    expect(w.find('.sr-color-label__clear').exists()).toBe(true)
  })

  it('versteckt Clear-Button im nicht-interaktiven Modus', () => {
    const w = factory({ interactive: false })
    expect(w.find('.sr-color-label__clear').exists()).toBe(false)
  })

  it('Clear-Button hat hidden-Klasse bei modelValue=null', () => {
    const w = factory({ modelValue: null })
    expect(w.find('.sr-color-label__clear').classes()).toContain('sr-color-label__clear--hidden')
  })

  it('Clear-Button sichtbar bei gesetzter Farbe', () => {
    const w = factory({ modelValue: 'Red' })
    expect(w.find('.sr-color-label__clear').classes()).not.toContain('sr-color-label__clear--hidden')
  })

  it('hat interactive-Klasse', () => {
    const w = factory({ interactive: true })
    expect(w.find('.sr-color-label--interactive').exists()).toBe(true)
  })

  it('hat compact-Klasse', () => {
    const w = factory({ compact: true })
    expect(w.find('.sr-color-label--compact').exists()).toBe(true)
  })

  it('deaktivierte Buttons bei interactive=false', () => {
    const w = factory({ interactive: false })
    w.findAll('.sr-color-label__dot').forEach(d => {
      expect(d.attributes('disabled')).toBeDefined()
    })
  })

  // ── Klick ─────────────────────────────────────────────────────────────────

  it('emittiert Farbe beim Klick', async () => {
    const w = factory({ modelValue: null })
    await w.findAll('.sr-color-label__dot')[0].trigger('click') // Red
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['Red'])
    expect(w.emitted('change')?.[0]).toEqual(['Red'])
  })

  it('toggle: gleiche Farbe klicken entfernt sie', async () => {
    const w = factory({ modelValue: 'Blue' })
    await w.findAll('.sr-color-label__dot')[3].trigger('click') // Blue nochmal
    expect(w.emitted('update:modelValue')?.[0]).toEqual([null])
  })

  it('wechselt von einer Farbe zur anderen', async () => {
    const w = factory({ modelValue: 'Red' })
    await w.findAll('.sr-color-label__dot')[2].trigger('click') // Green
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['Green'])
  })

  it('Clear-Button emittiert null', async () => {
    const w = factory({ modelValue: 'Yellow' })
    await w.find('.sr-color-label__clear').trigger('click')
    expect(w.emitted('update:modelValue')?.[0]).toEqual([null])
  })

  it('Clear-Button bei modelValue=null emittiert nichts', async () => {
    const w = factory({ modelValue: null })
    await w.find('.sr-color-label__clear').trigger('click')
    expect(w.emitted('update:modelValue')).toBeFalsy()
  })

  it('emittiert nichts bei Klick wenn nicht interaktiv', async () => {
    const w = factory({ interactive: false })
    await w.findAll('.sr-color-label__dot')[0].trigger('click')
    expect(w.emitted('update:modelValue')).toBeFalsy()
  })

  // ── Tastatur ──────────────────────────────────────────────────────────────

  it('Space-Taste toggled Farbe', async () => {
    const w = factory({ modelValue: null })
    await w.findAll('.sr-color-label__dot')[1].trigger('keydown', { key: ' ' }) // Yellow
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['Yellow'])
  })

  it('Enter-Taste toggled Farbe', async () => {
    const w = factory({ modelValue: null })
    await w.findAll('.sr-color-label__dot')[4].trigger('keydown', { key: 'Enter' }) // Purple
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['Purple'])
  })

  it('andere Tasten emittieren nichts', async () => {
    const w = factory({ modelValue: null })
    await w.findAll('.sr-color-label__dot')[0].trigger('keydown', { key: 'a' })
    expect(w.emitted('update:modelValue')).toBeFalsy()
  })

  // ── setByShortcut Expose ──────────────────────────────────────────────────

  it.each([
    ['6', 'Red'],
    ['7', 'Yellow'],
    ['8', 'Green'],
    ['9', 'Blue'],
    ['V', 'Purple'],
    ['v', 'Purple'],
  ])('setByShortcut("%s") setzt %s', (key, expected) => {
    const w = factory({ modelValue: null })
    const result = w.vm.setByShortcut(key)
    expect(result).toBe(true)
    expect(w.emitted('update:modelValue')?.[0]).toEqual([expected])
  })

  it('setByShortcut toggled bei bereits gesetzter Farbe', () => {
    const w = factory({ modelValue: 'Red' })
    w.vm.setByShortcut('6')
    expect(w.emitted('update:modelValue')?.[0]).toEqual([null])
  })

  it('setByShortcut mit ungültigem Key gibt false zurück', () => {
    const w = factory({ modelValue: null })
    const result = w.vm.setByShortcut('5')
    expect(result).toBe(false)
    expect(w.emitted('update:modelValue')).toBeFalsy()
  })

  // ── setColor Expose ───────────────────────────────────────────────────────

  it('setColor setzt Farbe direkt', () => {
    const w = factory({ modelValue: null })
    w.vm.setColor('Purple')
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['Purple'])
    expect(w.emitted('change')?.[0]).toEqual(['Purple'])
  })

  // ── ARIA ──────────────────────────────────────────────────────────────────

  it('radio-Buttons haben aria-checked', () => {
    const w = factory({ modelValue: 'Green' })
    const dots = w.findAll('.sr-color-label__dot')
    expect(dots[2].attributes('aria-checked')).toBe('true')
    expect(dots[0].attributes('aria-checked')).toBe('false')
  })

  it('hat role=radiogroup', () => {
    const w = factory()
    expect(w.find('.sr-color-label').attributes('role')).toBe('radiogroup')
  })

  // ── Alle 5 Farben einzeln ─────────────────────────────────────────────────

  it.each(['Red', 'Yellow', 'Green', 'Blue', 'Purple'])(
    'zeigt aktiven Zustand für %s',
    (color) => {
      const w = factory({ modelValue: color })
      const active = w.findAll('.sr-color-label__dot--active')
      expect(active).toHaveLength(1)
      expect(active[0].classes()).toContain(`sr-color-label__dot--${color.toLowerCase()}`)
    }
  )
})
