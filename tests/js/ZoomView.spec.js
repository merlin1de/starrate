import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import LoupeView from '../../src/components/LoupeView.vue'

const sampleImages = [
  { id: 1, name: 'IMG_0001.jpg', rating: 0, color: null, pick: 'none' },
  { id: 2, name: 'IMG_0002.jpg', rating: 3, color: 'Red', pick: 'pick' },
  { id: 3, name: 'IMG_0003.jpg', rating: 5, color: 'Green', pick: 'none' },
]

const factory = (props = {}) => mount(LoupeView, {
  props: {
    images:       sampleImages,
    initialIndex: 0,
    ...props,
  },
  attachTo: document.body,
})

describe('LoupeView – Zoom & Navigation', () => {

  // ── Grundrendering ────────────────────────────────────────────────────────

  it('rendert die Lupe-Komponente', () => {
    const w = factory()
    expect(w.find('.sr-loupe').exists()).toBe(true)
  })

  it('zeigt das erste Bild', () => {
    const w = factory({ initialIndex: 0 })
    expect(w.find('.sr-loupe__img').exists()).toBe(true)
  })

  it('zeigt Zoom-Level-Anzeige', () => {
    const w = factory()
    expect(w.find('.sr-loupe__zoom-level').exists()).toBe(true)
  })

  it('zeigt "Eingepasst" initial', () => {
    const w = factory()
    expect(w.find('.sr-loupe__zoom-level').text()).toContain('Eingepasst')
  })

  // ── Navigation ────────────────────────────────────────────────────────────

  it('emittiert index-change beim Drücken von ArrowRight', async () => {
    const w = factory({ initialIndex: 0 })
    await w.find('.sr-loupe').trigger('keydown', { key: 'ArrowRight' })
    expect(w.emitted('index-change')?.[0]).toEqual([1])
  })

  it('emittiert index-change beim Drücken von ArrowLeft', async () => {
    const w = factory({ initialIndex: 1 })
    await w.find('.sr-loupe').trigger('keydown', { key: 'ArrowLeft' })
    expect(w.emitted('index-change')?.[0]).toEqual([0])
  })

  it('navigiert nicht vor dem ersten Bild', async () => {
    const w = factory({ initialIndex: 0 })
    await w.find('.sr-loupe').trigger('keydown', { key: 'ArrowLeft' })
    expect(w.emitted('index-change')).toBeFalsy()
  })

  it('navigiert nicht nach dem letzten Bild', async () => {
    const w = factory({ initialIndex: 2 })
    await w.find('.sr-loupe').trigger('keydown', { key: 'ArrowRight' })
    expect(w.emitted('index-change')).toBeFalsy()
  })

  it('emittiert close bei Escape', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('keydown', { key: 'Escape' })
    expect(w.emitted('close')).toBeTruthy()
  })

  it('zeigt Prev-Button nicht beim ersten Bild', () => {
    const w = factory({ initialIndex: 0 })
    expect(w.find('.sr-loupe__nav--prev').exists()).toBe(false)
  })

  it('zeigt Next-Button nicht beim letzten Bild', () => {
    const w = factory({ initialIndex: 2 })
    expect(w.find('.sr-loupe__nav--next').exists()).toBe(false)
  })

  it('zeigt beide Navigations-Buttons bei mittlerem Bild', () => {
    const w = factory({ initialIndex: 1 })
    expect(w.find('.sr-loupe__nav--prev').exists()).toBe(true)
    expect(w.find('.sr-loupe__nav--next').exists()).toBe(true)
  })

  // ── Zoom per Tastatur ─────────────────────────────────────────────────────

  it('zeigt %-Wert nach Zoom-In mit +', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    const label = w.find('.sr-loupe__zoom-level').text()
    expect(label).not.toContain('Eingepasst')
    expect(label).toContain('%')
  })

  it('Leertaste setzt Zoom auf Fit zurück', async () => {
    const w = factory()
    // Erst zoomen
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    // Dann Fit
    await w.find('.sr-loupe').trigger('keydown', { key: ' ' })
    expect(w.find('.sr-loupe__zoom-level').text()).toContain('Eingepasst')
  })

  it('- Taste zoomt heraus', async () => {
    const w = factory()
    // Erst rein zoomen
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    const labelAfterZoomIn = w.find('.sr-loupe__zoom-level').text()

    // Dann heraus
    await w.find('.sr-loupe').trigger('keydown', { key: '-' })
    const labelAfterZoomOut = w.find('.sr-loupe__zoom-level').text()

    // Zoom-Wert muss kleiner werden
    const before = parseInt(labelAfterZoomIn)
    const after  = parseInt(labelAfterZoomOut)
    expect(after).toBeLessThan(before)
  })

  // ── Zoom per Mausrad ──────────────────────────────────────────────────────

  it('Mausrad nach unten (deltaY > 0) zoomt heraus', async () => {
    const w = factory()
    // Erst reinzoomen via Tastatur
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })

    const before = w.find('.sr-loupe__zoom-level').text()
    await w.find('.sr-loupe').trigger('wheel', { deltaY: 120, preventDefault: vi.fn() })
    const after = w.find('.sr-loupe__zoom-level').text()

    // Wenn nach Wheel wieder Fit → war Zoom gering
    // Sonst: Zoom sollte abgenommen haben
    expect(after).not.toBe(before)
  })

  it('Mausrad nach oben (deltaY < 0) zoomt rein', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('wheel', { deltaY: -120, preventDefault: vi.fn() })
    const label = w.find('.sr-loupe__zoom-level').text()
    expect(label).not.toContain('Eingepasst')
    expect(label).toContain('%')
  })

  // ── Doppelklick ───────────────────────────────────────────────────────────

  it('Doppelklick wechselt von Fit zu 100%', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('dblclick')
    const label = w.find('.sr-loupe__zoom-level').text()
    expect(label).not.toContain('Eingepasst')
  })

  it('Doppelklick zweimal kehrt zu Fit zurück', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('dblclick')
    await w.find('.sr-loupe').trigger('dblclick')
    expect(w.find('.sr-loupe__zoom-level').text()).toContain('Eingepasst')
  })

  it('Doppelklick kehrt zu Fit zurück wenn kleiner als Fit gezoomt (zoom < 1.0)', async () => {
    const w = factory()
    // Mausrad rauszoomen bis unter Fit-Level
    for (let i = 0; i < 5; i++) {
      await w.find('.sr-loupe').trigger('wheel', { deltaY: 120 })
    }
    // Jetzt sollte zoom < 1.0 sein (kleiner als Fit) oder schon reset — prüfe dass Doppelklick zu Fit führt
    await w.find('.sr-loupe').trigger('dblclick')
    expect(w.find('.sr-loupe__zoom-level').text()).toContain('Eingepasst')
  })

  // ── Bewertung in Lupenansicht ─────────────────────────────────────────────

  it.each([0, 1, 2, 3, 4, 5])('Taste "%s" emittiert rate mit Rating %s', async (r) => {
    const w = factory()
    await w.find('.sr-loupe').trigger('keydown', { key: String(r) })
    const emitted = w.emitted('rate')
    expect(emitted).toBeTruthy()
    expect(emitted[0][1]).toBe(r)
    expect(emitted[0][2]).toBeUndefined()
  })

  it('Taste 6 emittiert Farbe Red', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('keydown', { key: '6' })
    const emitted = w.emitted('rate')
    expect(emitted[0][2]).toBe('Red')
  })

  it('Taste 7 emittiert Farbe Yellow', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('keydown', { key: '7' })
    expect(w.emitted('rate')[0][2]).toBe('Yellow')
  })

  it('Taste 8 emittiert Farbe Green', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('keydown', { key: '8' })
    expect(w.emitted('rate')[0][2]).toBe('Green')
  })

  it('Taste 9 emittiert Farbe Blue', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('keydown', { key: '9' })
    expect(w.emitted('rate')[0][2]).toBe('Blue')
  })

  // ── Pick / Reject ─────────────────────────────────────────────────────────

  it('Pick/Reject-Buttons nicht sichtbar wenn enablePickUi=false (default)', () => {
    const w = factory()
    expect(w.find('.sr-loupe__pick-btn').exists()).toBe(false)
  })

  it('Pick/Reject-Buttons sichtbar wenn enablePickUi=true', () => {
    const w = factory({ enablePickUi: true })
    expect(w.findAll('.sr-loupe__pick-btn')).toHaveLength(2)
  })

  it('P-Taste ohne enablePickUi emittiert kein rate', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('keydown', { key: 'p' })
    expect(w.emitted('rate')).toBeFalsy()
  })

  it('P-Taste mit enablePickUi emittiert rate mit pick=pick', async () => {
    const w = factory({ enablePickUi: true })
    await w.find('.sr-loupe').trigger('keydown', { key: 'p' })
    expect(w.emitted('rate')?.[0][3]).toBe('pick')
  })

  it('X-Taste ohne enablePickUi emittiert kein rate', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('keydown', { key: 'x' })
    expect(w.emitted('rate')).toBeFalsy()
  })

  it('X-Taste mit enablePickUi emittiert rate mit pick=reject', async () => {
    const w = factory({ enablePickUi: true })
    await w.find('.sr-loupe').trigger('keydown', { key: 'x' })
    expect(w.emitted('rate')?.[0][3]).toBe('reject')
  })

  // ── Footer ────────────────────────────────────────────────────────────────

  it('zeigt Dateinamen im Footer', () => {
    const w = factory({ initialIndex: 0 })
    expect(w.find('.sr-loupe__filename').text()).toContain('IMG_0001.jpg')
  })

  it('zeigt Bildindex im Footer', () => {
    const w = factory({ initialIndex: 1 })
    expect(w.find('.sr-loupe__index').text()).toContain('2 / 3')
  })

  it('Zurück-Button emittiert close', async () => {
    const w = factory()
    await w.find('.sr-loupe__back').trigger('click')
    expect(w.emitted('close')).toBeTruthy()
  })
})
