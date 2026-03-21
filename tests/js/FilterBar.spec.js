import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import FilterBar from '../../src/components/FilterBar.vue'

const makeRouter = () => createRouter({
  history: createMemoryHistory(),
  routes: [{ path: '/', component: { template: '<div/>' } }],
})

const defaultFilter = () => ({
  minRating:   0,
  exactRating: null,
  color:       null,
  pick:        null,
})

const factory = (props = {}, router = null) => {
  const r = router ?? makeRouter()
  return mount(FilterBar, {
    props: {
      filter:        defaultFilter(),
      total:         100,
      filteredCount: 100,
      mode:          'grid',
      ...props,
    },
    global: { plugins: [r] },
  })
}

describe('FilterBar', () => {

  // ── Rendering ─────────────────────────────────────────────────────────────

  it('rendert alle Sterne-Optionen', () => {
    const w = factory()
    const pills = w.findAll('.sr-filterbar__pill:not(.sr-filterbar__pill--color)')
    expect(pills.length).toBeGreaterThanOrEqual(4) // ★5, ★4, ≥3★, Unbewertet
  })

  it('rendert 5 Farb-Pills', () => {
    const w = factory()
    expect(w.findAll('.sr-filterbar__pill--color')).toHaveLength(5)
  })

  it('zeigt keinen Reset-Button wenn kein Filter aktiv', () => {
    const w = factory()
    expect(w.find('.sr-filterbar__reset').exists()).toBe(false)
  })

  it('zeigt Reset-Button wenn Filter aktiv', () => {
    const w = factory({ filter: { ...defaultFilter(), minRating: 3 } })
    expect(w.find('.sr-filterbar__reset').exists()).toBe(true)
  })

  it('zeigt Grid/Loupe Modus-Buttons', () => {
    const w = factory()
    expect(w.findAll('.sr-filterbar__mode-btn')).toHaveLength(2)
  })

  // ── Sterne-Filter ─────────────────────────────────────────────────────────

  it('setzt exactRating=5 beim Klick auf ★★★★★', async () => {
    const w = factory()
    const pills = w.findAll('.sr-filterbar__pill:not(.sr-filterbar__pill--color):not(.sr-filterbar__reset)')
    await pills[0].trigger('click') // erste Sterne-Pill = ★★★★★
    const emitted = w.emitted('update:filter')
    expect(emitted).toBeTruthy()
    expect(emitted[0][0].exactRating).toBe(5)
  })

  it('setzt minRating=3 beim Klick auf ≥3★', async () => {
    const w = factory()
    const pills = w.findAll('.sr-filterbar__pill:not(.sr-filterbar__pill--color):not(.sr-filterbar__reset)')
    await pills[2].trigger('click') // 3. = ≥3★
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0].minRating).toBe(3)
    expect(emitted[0][0].exactRating).toBeNull()
  })

  it('deaktiviert Sterne-Filter bei nochmaligem Klick', async () => {
    const w = factory({ filter: { ...defaultFilter(), exactRating: 5 } })
    const pills = w.findAll('.sr-filterbar__pill:not(.sr-filterbar__pill--color):not(.sr-filterbar__reset)')
    await pills[0].trigger('click')
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0].exactRating).toBeNull()
    expect(emitted[0][0].minRating).toBe(0)
  })

  it('hat --active Klasse für aktive Sterne-Pill', () => {
    const w = factory({ filter: { ...defaultFilter(), exactRating: 5 } })
    const pills = w.findAll('.sr-filterbar__pill:not(.sr-filterbar__pill--color)')
    expect(pills[0].classes()).toContain('sr-filterbar__pill--active')
  })

  // ── Farb-Filter ───────────────────────────────────────────────────────────

  it('setzt Farb-Filter beim Klick auf Farb-Pill', async () => {
    const w = factory()
    await w.findAll('.sr-filterbar__pill--color')[0].trigger('click') // Rot
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0].color).toBe('Red')
  })

  it('entfernt Farb-Filter bei nochmaligem Klick', async () => {
    const w = factory({ filter: { ...defaultFilter(), color: 'Red' } })
    await w.findAll('.sr-filterbar__pill--color')[0].trigger('click')
    expect(w.emitted('update:filter')[0][0].color).toBeNull()
  })

  it('kann Sterne- und Farb-Filter kombinieren', async () => {
    const w = factory({ filter: { ...defaultFilter(), exactRating: 4 } })
    await w.findAll('.sr-filterbar__pill--color')[2].trigger('click') // Grün
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0].exactRating).toBe(4)
    expect(emitted[0][0].color).toBe('Green')
  })

  // ── Pick/Reject-Filter ────────────────────────────────────────────────────

  it('aktiviert Pick-Filter', async () => {
    const w = factory()
    const pickBtn = w.findAll('.sr-filterbar__group')[2].find('button:first-child')
    await pickBtn.trigger('click')
    expect(w.emitted('update:filter')[0][0].pick).toBe('pick')
  })

  it('deaktiviert Pick-Filter bei nochmaligem Klick', async () => {
    const w = factory({ filter: { ...defaultFilter(), pick: 'pick' } })
    const pickBtn = w.findAll('.sr-filterbar__group')[2].find('button:first-child')
    await pickBtn.trigger('click')
    expect(w.emitted('update:filter')[0][0].pick).toBeNull()
  })

  // ── Reset ─────────────────────────────────────────────────────────────────

  it('"Alle anzeigen" setzt alle Filter zurück', async () => {
    const w = factory({
      filter: { minRating: 3, exactRating: null, color: 'Red', pick: 'pick' },
    })
    await w.find('.sr-filterbar__reset').trigger('click')
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0]).toEqual({ minRating: 0, exactRating: null, color: null, pick: null })
  })

  // ── URL-Parameter ─────────────────────────────────────────────────────────

  it('aktualisiert URL-Parameter bei Filter-Wechsel', async () => {
    const router = makeRouter()
    const replaceSpy = vi.spyOn(router, 'replace')
    const w = factory({}, router)

    await w.findAll('.sr-filterbar__pill--color')[1].trigger('click') // Gelb
    expect(replaceSpy).toHaveBeenCalledWith(expect.objectContaining({
      query: expect.objectContaining({ color: 'Yellow' }),
    }))
  })

  it('setzt keine URL-Query wenn kein Filter aktiv', async () => {
    const router = makeRouter()
    const replaceSpy = vi.spyOn(router, 'replace')
    const w = factory({ filter: { ...defaultFilter(), color: 'Red' } }, router)

    // Filter zurücksetzen
    await w.find('.sr-filterbar__reset').trigger('click')
    expect(replaceSpy).toHaveBeenCalledWith({ query: {} })
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
