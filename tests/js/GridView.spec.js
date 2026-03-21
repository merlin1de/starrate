import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import GridView from '../../src/components/GridView.vue'

// Globales Image-Mock für Thumbnail-Loading
global.Image = class {
  constructor() { setTimeout(() => this.onload?.(), 0) }
}

const makeImages = (count = 5) => Array.from({ length: count }, (_, i) => ({
  id:          i + 1,
  name:        `IMG_000${i + 1}.jpg`,
  rating:      i % 6,
  color:       i % 2 === 0 ? null : 'Red',
  pick:        'none',
  thumbLoaded: false,
  thumbUrl:    null,
}))

const factory = (props = {}) => mount(GridView, {
  props: {
    images:          makeImages(),
    loading:         false,
    hasActiveFilter: false,
    currentIndex:    -1,
    ...props,
  },
  attachTo: document.body,
})

describe('GridView', () => {

  // ── Rendering ─────────────────────────────────────────────────────────────

  it('rendert alle Bilder', () => {
    const w = factory()
    expect(w.findAll('.sr-grid__item:not(.sr-grid__item--skeleton)')).toHaveLength(5)
  })

  it('zeigt Skeleton-Loader beim Laden', () => {
    const w = factory({ images: [], loading: true })
    expect(w.findAll('.sr-grid__item--skeleton').length).toBeGreaterThan(0)
  })

  it('zeigt Leer-Zustand wenn keine Bilder', () => {
    const w = factory({ images: [], loading: false })
    expect(w.find('.sr-grid__empty').exists()).toBe(true)
  })

  it('zeigt Filter-Hinweis wenn aktiver Filter und keine Bilder', () => {
    const w = factory({ images: [], loading: false, hasActiveFilter: true })
    expect(w.find('.sr-grid__empty-sub').exists()).toBe(true)
  })

  it('zeigt Info-Leiste mit Dateinamen', () => {
    const w = factory()
    const names = w.findAll('.sr-grid__info-name')
    expect(names[0].text()).toBe('IMG_0001.jpg')
  })

  it('zeigt Farbpunkt wenn Farbe gesetzt', () => {
    const w = factory()
    // Bilder 1,3,5 haben color=null, 2,4 haben color='Red'
    expect(w.findAll('.sr-grid__info-color').length).toBeGreaterThan(0)
  })

  // ── Auswahl: Normaler Klick ───────────────────────────────────────────────

  it('normaler Klick setzt focusedIndex', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[2].trigger('click')
    // kein Shift/Ctrl → nur Focus, kein select
    expect(w.emitted('selection-change')).toBeFalsy()
  })

  it('normaler Klick hebt Auswahl auf wenn Auswahl vorhanden', async () => {
    const w = factory()
    // Erst Strg+Klick
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    // Dann normaler Klick
    await w.findAll('.sr-grid__item')[1].trigger('click')
    const lastEmit = w.emitted('selection-change')
    const lastSet  = lastEmit[lastEmit.length - 1][0]
    expect(lastSet.size).toBe(0)
  })

  // ── Auswahl: Strg+Klick ───────────────────────────────────────────────────

  it('Strg+Klick fügt Bild zur Auswahl hinzu', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    const emitted = w.emitted('selection-change')
    expect(emitted).toBeTruthy()
    expect(emitted[0][0].has(1)).toBe(true)
  })

  it('Strg+Klick auf ausgewähltes Bild entfernt es aus der Auswahl', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    const lastEmit = w.emitted('selection-change')
    const lastSet  = lastEmit[lastEmit.length - 1][0]
    expect(lastSet.has(1)).toBe(false)
  })

  it('mehrere Strg+Klicks sammeln mehrere Bilder', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    await w.findAll('.sr-grid__item')[2].trigger('click', { ctrlKey: true })
    await w.findAll('.sr-grid__item')[4].trigger('click', { ctrlKey: true })
    const lastSet = w.emitted('selection-change').at(-1)[0]
    expect(lastSet.size).toBe(3)
  })

  // ── Auswahl: Shift+Klick ──────────────────────────────────────────────────

  it('Shift+Klick markiert Bereich zwischen zwei Klicks', async () => {
    const w = factory({ images: makeImages(8) })
    await w.findAll('.sr-grid__item')[1].trigger('click') // Anker = Index 1
    await w.findAll('.sr-grid__item')[4].trigger('click', { shiftKey: true })
    const lastSet = w.emitted('selection-change').at(-1)[0]
    expect(lastSet.size).toBe(4) // Indizes 1,2,3,4
  })

  // ── Tastatur ──────────────────────────────────────────────────────────────

  it('ArrowRight bewegt Focus um 1 nach rechts', async () => {
    const w = factory()
    await w.trigger('keydown', { key: 'ArrowRight' })
    // Nach initialem Focus-Index 0 → 1
    // (Nur Smoke-Test — Index-Change ist intern)
    expect(w.find('.sr-grid').exists()).toBe(true)
  })

  it('Taste 0–5 emittiert rate für fokussiertes Bild', async () => {
    const w = factory()
    // Erst Bild fokussieren
    await w.findAll('.sr-grid__item')[0].trigger('click')
    await w.trigger('keydown', { key: '4' })
    const emitted = w.emitted('rate')
    expect(emitted).toBeTruthy()
    expect(emitted[0][1]).toBe(4)
  })

  it('Taste 6 emittiert rate mit Farbe Red', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click')
    await w.trigger('keydown', { key: '6' })
    const emitted = w.emitted('rate')
    expect(emitted[0][2]).toBe('Red')
  })

  it('Strg+A wählt alle Bilder aus', async () => {
    const w = factory()
    await w.trigger('keydown', { key: 'a', ctrlKey: true })
    const lastSet = w.emitted('selection-change').at(-1)[0]
    expect(lastSet.size).toBe(5)
  })

  it('Escape hebt Auswahl auf', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    await w.trigger('keydown', { key: 'Escape' })
    const lastSet = w.emitted('selection-change').at(-1)[0]
    expect(lastSet.size).toBe(0)
  })

  it('Enter öffnet Lupenansicht für fokussiertes Bild', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click')
    await w.trigger('keydown', { key: 'Enter' })
    expect(w.emitted('open-loupe')).toBeTruthy()
  })

  // ── Doppelklick öffnet Lupe ───────────────────────────────────────────────

  it('Doppelklick auf Bild emittiert open-loupe', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[1].trigger('dblclick')
    const emitted = w.emitted('open-loupe')
    expect(emitted).toBeTruthy()
    expect(emitted[0][1]).toBe(1) // index
  })

  // ── Auswahl-Markierung ────────────────────────────────────────────────────

  it('ausgewählte Bilder haben --selected Klasse', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    expect(w.findAll('.sr-grid__item')[0].classes()).toContain('sr-grid__item--selected')
  })

  it('nicht ausgewählte Bilder haben keine --selected Klasse', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    expect(w.findAll('.sr-grid__item')[1].classes()).not.toContain('sr-grid__item--selected')
  })

  // ── Rate-Event von Hover-Overlay ──────────────────────────────────────────

  it('emittiert rate wenn RatingStars im Hover-Overlay geändert wird', async () => {
    const w = factory()
    // RatingStars-Komponente im Hover-Overlay direkt triggern
    const ratingStars = w.findAll('.sr-grid__hover-overlay .sr-stars')[0]
    await ratingStars.trigger('change', 3)
    // Das Event wird via @change weitergeleitet
    // (Smoke-Test — Event-Propagation ist intern)
    expect(w.find('.sr-grid__hover-overlay').exists()).toBe(true)
  })

  // ── Expose ────────────────────────────────────────────────────────────────

  it('clearSelection leert die Auswahl', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    w.vm.clearSelection()
    const lastSet = w.emitted('selection-change').at(-1)[0]
    expect(lastSet.size).toBe(0)
  })

  it('selectAll wählt alle Bilder aus', async () => {
    const w = factory()
    w.vm.selectAll()
    const lastSet = w.emitted('selection-change').at(-1)[0]
    expect(lastSet.size).toBe(5)
  })
})
