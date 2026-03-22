import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import FilterBar from '../../src/components/FilterBar.vue'

const defaultFilter = () => ({
  minRating:   0,
  exactRating: null,
  maxRating:   null,
  color:       null,
  pick:        null,
})

const factory = (props = {}) => mount(FilterBar, {
  props: {
    filter:        defaultFilter(),
    total:         100,
    filteredCount: 100,
    mode:          'grid',
    ...props,
  },
})

describe('FilterBar', () => {

  // ── Rendering ─────────────────────────────────────────────────────────────

  it('rendert alle Sterne-Optionen', () => {
    const w = factory()
    const pills = w.findAll('.sr-filterbar__pill:not(.sr-filterbar__colordot)')
    expect(pills.length).toBeGreaterThanOrEqual(4) // ★5, ★4, ≥3★, Unbewertet
  })

  it('rendert 5 Farb-Kreise', () => {
    const w = factory()
    expect(w.findAll('.sr-filterbar__colordot')).toHaveLength(5)
  })

  it('Status-Bereich ist versteckt wenn kein Filter aktiv', async () => {
    const w = factory()
    await w.vm.$nextTick()
    expect(w.find('.sr-filterbar__status').element.style.visibility).toBe('hidden')
  })

  it('Status-Bereich ist sichtbar wenn Filter aktiv', () => {
    const w = factory({ filter: { ...defaultFilter(), minRating: 3 } })
    expect(w.find('.sr-filterbar__status').element.style.visibility).not.toBe('hidden')
  })

  it('zeigt Grid/Loupe Modus-Buttons', () => {
    const w = factory()
    expect(w.findAll('.sr-filterbar__mode-btn')).toHaveLength(2)
  })

  // ── Sterne-Filter ─────────────────────────────────────────────────────────

  it('setzt exactRating=5 beim Klick auf ★★★★★ (default op=)', async () => {
    const w = factory()
    // Stern-Pills: ★★★★★ ★★★★ ★★★ ★★ ★ ○ → index 0 = ★★★★★
    const stars = w.findAll('.sr-filterbar__pill--star')
    await stars[0].trigger('click')
    const emitted = w.emitted('update:filter')
    expect(emitted).toBeTruthy()
    expect(emitted[0][0].exactRating).toBe(5)
    expect(emitted[0][0].minRating).toBe(0)
  })

  it('setzt minRating=5 beim Klick op=≥, dann ★★★★★', async () => {
    const w = factory()
    await w.findAll('.sr-filterbar__op')[0].trigger('click') // ≥ Operator
    const stars = w.findAll('.sr-filterbar__pill--star')
    await stars[0].trigger('click') // ★★★★★ = index 0
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0].minRating).toBe(5)
    expect(emitted[0][0].exactRating).toBeNull()
  })

  it('setzt exactRating=3 beim Klick auf ★★★ (default op=)', async () => {
    const w = factory()
    const stars = w.findAll('.sr-filterbar__pill--star')
    await stars[2].trigger('click') // ★★★ = index 2
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0].exactRating).toBe(3)
    expect(emitted[0][0].minRating).toBe(0)
  })

  it('deaktiviert Sterne-Filter bei nochmaligem Klick', async () => {
    const w = factory({ filter: { ...defaultFilter(), exactRating: 5 } })
    // watch setzt selectedOp='=' → ★★★★★ (index 0) ist aktiv → Klick deaktiviert
    const stars = w.findAll('.sr-filterbar__pill--star')
    await stars[0].trigger('click')
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0].exactRating).toBeNull()
    expect(emitted[0][0].minRating).toBe(0)
  })

  it('hat --active Klasse für aktive Sterne-Pill (exactRating=5)', () => {
    const w = factory({ filter: { ...defaultFilter(), exactRating: 5 } })
    // selectedOp wird durch watch auf '=' gesetzt → ★★★★★ (index 0) ist aktiv
    const stars = w.findAll('.sr-filterbar__pill--star')
    expect(stars[0].classes()).toContain('sr-filterbar__pill--active')
  })

  it('setzt maxRating=3 beim Klick op=≤, dann ★★★', async () => {
    const w = factory()
    await w.findAll('.sr-filterbar__op')[2].trigger('click') // ≤ Operator
    const stars = w.findAll('.sr-filterbar__pill--star')
    await stars[2].trigger('click') // ★★★ = index 2
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0].maxRating).toBe(3)
    expect(emitted[0][0].exactRating).toBeNull()
    expect(emitted[0][0].minRating).toBe(0)
  })

  // ── Farb-Filter ───────────────────────────────────────────────────────────

  it('setzt Farb-Filter beim Klick auf Farb-Pill', async () => {
    const w = factory()
    await w.findAll('.sr-filterbar__colordot')[0].trigger('click') // Rot
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0].color).toBe('Red')
  })

  it('entfernt Farb-Filter bei nochmaligem Klick', async () => {
    const w = factory({ filter: { ...defaultFilter(), color: 'Red' } })
    await w.findAll('.sr-filterbar__colordot')[0].trigger('click')
    expect(w.emitted('update:filter')[0][0].color).toBeNull()
  })

  it('kann Sterne- und Farb-Filter kombinieren', async () => {
    const w = factory({ filter: { ...defaultFilter(), exactRating: 4 } })
    await w.findAll('.sr-filterbar__colordot')[2].trigger('click') // Grün
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0].exactRating).toBe(4)
    expect(emitted[0][0].color).toBe('Green')
  })

  // ── Pick/Reject-Filter ────────────────────────────────────────────────────

  it('Pick/Reject-Buttons nicht sichtbar wenn enablePickUi=false (default)', () => {
    const w = factory()
    expect(w.find('.sr-filterbar__pill--pick').exists()).toBe(false)
    expect(w.find('.sr-filterbar__pill--reject').exists()).toBe(false)
  })

  it('Pick/Reject-Buttons sichtbar wenn enablePickUi=true', () => {
    const w = factory({ enablePickUi: true })
    expect(w.find('.sr-filterbar__pill--pick').exists()).toBe(true)
    expect(w.find('.sr-filterbar__pill--reject').exists()).toBe(true)
  })

  it('aktiviert Pick-Filter', async () => {
    const w = factory({ enablePickUi: true })
    await w.find('.sr-filterbar__pill--pick').trigger('click')
    expect(w.emitted('update:filter')[0][0].pick).toBe('pick')
  })

  it('deaktiviert Pick-Filter bei nochmaligem Klick', async () => {
    const w = factory({ filter: { ...defaultFilter(), pick: 'pick' }, enablePickUi: true })
    await w.find('.sr-filterbar__pill--pick').trigger('click')
    expect(w.emitted('update:filter')[0][0].pick).toBeNull()
  })

  it('aktiviert Reject-Filter', async () => {
    const w = factory({ enablePickUi: true })
    await w.find('.sr-filterbar__pill--reject').trigger('click')
    expect(w.emitted('update:filter')[0][0].pick).toBe('reject')
  })

  // ── Reset ─────────────────────────────────────────────────────────────────

  it('"Alle anzeigen" setzt alle Filter zurück', async () => {
    const w = factory({
      filter: { minRating: 3, exactRating: null, color: 'Red', pick: 'pick' },
    })
    await w.find('.sr-filterbar__reset').trigger('click')
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0]).toEqual({ minRating: 0, exactRating: null, maxRating: null, color: null, pick: null })
  })

  // ── Modus-Toggle ─────────────────────────────────────────────────────────

  it('emittiert toggle-mode beim Klick auf Loupe-Button', async () => {
    const w = factory({ mode: 'grid' })
    const loupBtn = w.findAll('.sr-filterbar__mode-btn')[1]
    await loupBtn.trigger('click')
    expect(w.emitted('toggle-mode')).toBeTruthy()
  })

  it('Grid-Button hat --active Klasse im Grid-Modus', () => {
    const w = factory({ mode: 'grid' })
    expect(w.findAll('.sr-filterbar__mode-btn')[0].classes())
      .toContain('sr-filterbar__mode-btn--active')
  })

  it('Loupe-Button hat --active Klasse im Loupe-Modus', () => {
    const w = factory({ mode: 'loupe' })
    expect(w.findAll('.sr-filterbar__mode-btn')[1].classes())
      .toContain('sr-filterbar__mode-btn--active')
  })
})
