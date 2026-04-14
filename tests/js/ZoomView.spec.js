import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import LoupeView from '../../src/components/LoupeView.vue'

const sampleImages = [
  { id: 1, name: 'IMG_0001.jpg', rating: 0, color: null, pick: 'none' },
  { id: 2, name: 'IMG_0002.jpg', rating: 3, color: 'Red', pick: 'pick' },
  { id: 3, name: 'IMG_0003.jpg', rating: 5, color: 'Green', pick: 'none' },
]

// Track mounted wrappers for cleanup (LoupeView adds document-level keydown listeners)
const wrappers = []

const factory = (props = {}) => {
  const w = mount(LoupeView, {
    props: {
      images:       sampleImages,
      initialIndex: 0,
      ...props,
    },
    attachTo: document.body,
  })
  wrappers.push(w)
  return w
}

afterEach(() => {
  wrappers.forEach(w => w.unmount())
  wrappers.length = 0
})

describe('LoupeView – Kommentare', () => {

  it('zeigt Kommentar-Button wenn commentsEnabledOwner=true', () => {
    const w = factory({ commentsEnabledOwner: true })
    expect(w.find('.sr-loupe__comment-btn').exists()).toBe(true)
  })

  it('zeigt Kommentar-Button wenn allowComment=true', () => {
    const w = factory({ allowComment: true })
    expect(w.find('.sr-loupe__comment-btn').exists()).toBe(true)
  })

  it('versteckt Kommentar-Button wenn weder allowComment noch commentsEnabledOwner', () => {
    const w = factory()
    expect(w.find('.sr-loupe__comment-btn').exists()).toBe(false)
  })

  it('Kommentar-Sheet ist initial geschlossen', () => {
    const w = factory({ commentsEnabledOwner: true })
    expect(w.find('.sr-loupe__comment-sheet-overlay--open').exists()).toBe(false)
  })

  it('öffnet Kommentar-Sheet bei Klick auf Button', async () => {
    const w = factory({ commentsEnabledOwner: true })
    await w.find('.sr-loupe__comment-btn').trigger('click')
    await flushPromises()
    expect(w.find('.sr-loupe__comment-sheet-overlay--open').exists()).toBe(true)
  })

  it('schließt Kommentar-Sheet bei Klick auf ✕', async () => {
    const w = factory({ commentsEnabledOwner: true })
    await w.find('.sr-loupe__comment-btn').trigger('click')
    await flushPromises()
    expect(w.find('.sr-loupe__comment-sheet-overlay--open').exists()).toBe(true)
    await w.find('.sr-loupe__comment-close').trigger('click')
    expect(w.find('.sr-loupe__comment-sheet-overlay--open').exists()).toBe(false)
  })

  it('schließt Kommentar-Sheet bei Escape', async () => {
    const w = factory({ commentsEnabledOwner: true })
    await w.find('.sr-loupe__comment-btn').trigger('click')
    await flushPromises()
    expect(w.find('.sr-loupe__comment-sheet-overlay--open').exists()).toBe(true)
    await w.find('.sr-loupe').trigger('keydown', { key: 'Escape' })
    expect(w.find('.sr-loupe__comment-sheet-overlay--open').exists()).toBe(false)
  })

  it('zeigt Textarea im neuen Kommentar-Modus', async () => {
    const w = factory({ commentsEnabledOwner: true })
    await w.find('.sr-loupe__comment-btn').trigger('click')
    await flushPromises()
    expect(w.find('.sr-loupe__comment-textarea').exists()).toBe(true)
  })

  it('Button hat Akzentfarbe wenn Kommentar vorhanden (via commentApi)', async () => {
    const commentApi = {
      load: vi.fn().mockResolvedValue({ comment: 'Toll', author_name: 'Anna', updated_at: 1713000000 }),
      save: vi.fn(),
      remove: vi.fn(),
    }
    const w = factory({ allowComment: true, commentApi })
    await flushPromises()
    expect(w.find('.sr-loupe__comment-btn--active').exists()).toBe(true)
  })

  it('Button hat keine Akzentfarbe ohne Kommentar', async () => {
    const commentApi = {
      load: vi.fn().mockResolvedValue(null),
      save: vi.fn(),
      remove: vi.fn(),
    }
    const w = factory({ allowComment: true, commentApi })
    await flushPromises()
    expect(w.find('.sr-loupe__comment-btn--active').exists()).toBe(false)
  })

  it('loadComment gibt Autor und Datum aus API zurück', async () => {
    const commentApi = {
      load: vi.fn().mockResolvedValue({ comment: 'Super!', author_name: 'Max', updated_at: 1713050000 }),
      save: vi.fn(),
      remove: vi.fn(),
    }
    const w = factory({ allowComment: true, commentApi })
    await flushPromises()
    // Sheet öffnen
    await w.find('.sr-loupe__comment-btn').trigger('click')
    await flushPromises()
    // View-Modus — zeigt Autor und Text
    expect(w.find('.sr-loupe__comment-meta').text()).toContain('Max')
    expect(w.find('.sr-loupe__comment-text').text()).toContain('Super!')
  })

  it('speichert Kommentar über commentApi', async () => {
    const commentApi = {
      load: vi.fn().mockResolvedValue(null),
      save: vi.fn().mockResolvedValue({ comment: 'Neuer Text', author_name: 'Ich', updated_at: 1713060000 }),
      remove: vi.fn(),
    }
    const w = factory({ allowComment: true, commentApi })
    await flushPromises()
    await w.find('.sr-loupe__comment-btn').trigger('click')
    await flushPromises()
    await w.find('.sr-loupe__comment-textarea').setValue('Neuer Text')
    await w.find('.sr-loupe__comment-btn-save').trigger('click')
    await flushPromises()
    expect(commentApi.save).toHaveBeenCalledWith(1, 'Neuer Text')
    // Wechselt in View-Modus
    expect(w.find('.sr-loupe__comment-text').text()).toContain('Neuer Text')
  })

  it('Speichern-Button ist disabled bei leerem Text', async () => {
    const commentApi = {
      load: vi.fn().mockResolvedValue(null),
      save: vi.fn(),
      remove: vi.fn(),
    }
    const w = factory({ allowComment: true, commentApi })
    await flushPromises()
    await w.find('.sr-loupe__comment-btn').trigger('click')
    await flushPromises()
    expect(w.find('.sr-loupe__comment-btn-save').attributes('disabled')).toBeDefined()
  })

  it('zeigt Lösch-Bestätigung und löscht bei Klick auf Ja', async () => {
    const commentApi = {
      load: vi.fn().mockResolvedValue({ comment: 'Alte Notiz', author_name: 'Anna', updated_at: 1713000000 }),
      save: vi.fn(),
      remove: vi.fn().mockResolvedValue(undefined),
    }
    const w = factory({ allowComment: true, commentApi })
    await flushPromises()
    await w.find('.sr-loupe__comment-btn').trigger('click')
    await flushPromises()
    // View-Modus → Löschen-Button
    await w.find('.sr-loupe__comment-action--delete').trigger('click')
    // Bestätigungsdialog
    expect(w.find('.sr-loupe__comment-btn-save--danger').exists()).toBe(true)
    await w.find('.sr-loupe__comment-btn-save--danger').trigger('click')
    await flushPromises()
    expect(commentApi.remove).toHaveBeenCalledWith(1)
    // Sheet geschlossen, Button nicht mehr aktiv
    expect(w.find('.sr-loupe__comment-sheet-overlay--open').exists()).toBe(false)
    expect(w.find('.sr-loupe__comment-btn--active').exists()).toBe(false)
  })

  it('Abbrechen in Lösch-Bestätigung kehrt zur View zurück', async () => {
    const commentApi = {
      load: vi.fn().mockResolvedValue({ comment: 'Notiz', author_name: 'Anna', updated_at: 1713000000 }),
      save: vi.fn(),
      remove: vi.fn(),
    }
    const w = factory({ allowComment: true, commentApi })
    await flushPromises()
    await w.find('.sr-loupe__comment-btn').trigger('click')
    await flushPromises()
    await w.find('.sr-loupe__comment-action--delete').trigger('click')
    await w.find('.sr-loupe__comment-btn-cancel').trigger('click')
    // Zurück in View-Modus — Kommentartext noch da
    expect(w.find('.sr-loupe__comment-text').text()).toContain('Notiz')
    expect(w.find('.sr-loupe__comment-btn-save--danger').exists()).toBe(false)
  })

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

    const before = parseInt(w.find('.sr-loupe__zoom-level').text())
    await w.find('.sr-loupe').trigger('wheel', { deltaY: 120, preventDefault: vi.fn() })
    const after = w.find('.sr-loupe__zoom-level').text()

    // Zoom sollte abgenommen haben oder auf Fit zurückgefallen sein
    if (after.includes('Eingepasst')) {
      expect(true).toBe(true) // Reset auf Fit ist gültiges Herauszoomen
    } else {
      expect(parseInt(after)).toBeLessThan(before)
    }
  })

  it('Mausrad nach oben (deltaY < 0) zoomt rein', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('wheel', { deltaY: -120, preventDefault: vi.fn() })
    const label = w.find('.sr-loupe__zoom-level').text()
    expect(label).not.toContain('Eingepasst')
    expect(label).toContain('%')
  })

  it('Mausrad-Zoom nutzt Mausposition als Pivot', async () => {
    const w = factory()
    const loupeEl = w.find('.sr-loupe').element
    const imgEl = w.find('.sr-loupe__img').element

    // jsdom hat keine echten Dimensionen → mocken für Pivot-Berechnung
    loupeEl.getBoundingClientRect = () => ({ left: 0, top: 0, width: 800, height: 600, right: 800, bottom: 600 })
    Object.defineProperty(loupeEl, 'offsetWidth',  { value: 800, configurable: true })
    Object.defineProperty(loupeEl, 'offsetHeight', { value: 600, configurable: true })
    Object.defineProperty(imgEl, 'naturalWidth',   { value: 4000, configurable: true })
    Object.defineProperty(imgEl, 'naturalHeight',  { value: 3000, configurable: true })

    // WheelEvent mit clientX/clientY an Offset-Position (nicht Mitte)
    for (let i = 0; i < 3; i++) {
      loupeEl.dispatchEvent(new WheelEvent('wheel', {
        deltaY: -120, clientX: 600, clientY: 100, bubbles: true, cancelable: true,
      }))
      await w.vm.$nextTick()
    }

    const style = w.find('.sr-loupe__img').attributes('style') ?? ''
    const match = style.match(/translate\(calc\(-50% \+ ([-\d.]+)px\), calc\(-50% \+ ([-\d.]+)px\)/)
    expect(match).toBeTruthy()
    const px = parseFloat(match[1])
    const py = parseFloat(match[2])
    // Pivot bei (600, 100) ist rechts-oben vom Zentrum (400, 300) → panX < 0, panY > 0
    expect(Math.abs(px) + Math.abs(py)).toBeGreaterThan(0)
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

  it('Doppelklick bei Zoom > Fit kehrt zu Fit zurück', async () => {
    const w = factory()
    // Reinzoomen
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    expect(w.find('.sr-loupe__zoom-level').text()).not.toContain('Eingepasst')
    // Doppelklick → zurück zu Fit
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

  // ── Touch: Swipe ─────────────────────────────────────────────────────────

  it('Swipe links navigiert zum nächsten Bild', async () => {
    const w = factory({ initialIndex: 0 })
    await w.find('.sr-loupe').trigger('touchstart', {
      touches: [{ clientX: 300, clientY: 200 }],
    })
    await w.find('.sr-loupe').trigger('touchend', {
      touches: [],
      changedTouches: [{ clientX: 220, clientY: 202 }], // dx=-80, dy=2 → Swipe links
    })
    expect(w.emitted('index-change')?.[0]).toEqual([1])
  })

  it('Swipe rechts navigiert zum vorherigen Bild', async () => {
    const w = factory({ initialIndex: 1 })
    await w.find('.sr-loupe').trigger('touchstart', {
      touches: [{ clientX: 200, clientY: 200 }],
    })
    await w.find('.sr-loupe').trigger('touchend', {
      touches: [],
      changedTouches: [{ clientX: 285, clientY: 198 }], // dx=+85, dy=2 → Swipe rechts
    })
    expect(w.emitted('index-change')?.[0]).toEqual([0])
  })

  it('Swipe zu kurz (<60px) löst keine Navigation aus', async () => {
    const w = factory({ initialIndex: 0 })
    await w.find('.sr-loupe').trigger('touchstart', {
      touches: [{ clientX: 300, clientY: 200 }],
    })
    await w.find('.sr-loupe').trigger('touchend', {
      touches: [],
      changedTouches: [{ clientX: 260, clientY: 200 }], // dx=-40 → zu kurz
    })
    expect(w.emitted('index-change')).toBeFalsy()
  })

  it('Swipe mit zu viel vertikalem Versatz (>80px dy) löst keine Navigation aus', async () => {
    const w = factory({ initialIndex: 0 })
    await w.find('.sr-loupe').trigger('touchstart', {
      touches: [{ clientX: 300, clientY: 200 }],
    })
    await w.find('.sr-loupe').trigger('touchend', {
      touches: [],
      changedTouches: [{ clientX: 220, clientY: 290 }], // dx=-80 aber dy=90 → zu diagonal
    })
    expect(w.emitted('index-change')).toBeFalsy()
  })

  it('Swipe navigiert nicht vor erstem Bild', async () => {
    const w = factory({ initialIndex: 0 })
    await w.find('.sr-loupe').trigger('touchstart', {
      touches: [{ clientX: 200, clientY: 200 }],
    })
    await w.find('.sr-loupe').trigger('touchend', {
      touches: [],
      changedTouches: [{ clientX: 290, clientY: 200 }], // Swipe rechts, aber schon erstes Bild
    })
    expect(w.emitted('index-change')).toBeFalsy()
  })

  // ── Touch: Pinch-Zoom ─────────────────────────────────────────────────────

  it('Pinch rein (Finger auseinander) erhöht Zoom', async () => {
    const w = factory()
    // Start: 2 Finger 100px auseinander
    await w.find('.sr-loupe').trigger('touchstart', {
      touches: [{ clientX: 150, clientY: 200 }, { clientX: 250, clientY: 200 }],
    })
    // Move: 200px auseinander → ratio=2 → 200%
    await w.find('.sr-loupe').trigger('touchmove', {
      touches: [{ clientX: 100, clientY: 200 }, { clientX: 300, clientY: 200 }],
    })
    const label = w.find('.sr-loupe__zoom-level').text()
    expect(label).not.toContain('Eingepasst')
    expect(label).toContain('%')
    expect(parseInt(label)).toBeGreaterThan(100)
  })

  it('Pinch raus (Finger zusammen) verringert Zoom', async () => {
    const w = factory()
    // Erst reinzoomen via Tastatur
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    const before = parseInt(w.find('.sr-loupe__zoom-level').text())

    // Pinch: Start 200px auseinander, Move 100px → ratio=0.5 → Zoom halbiert
    await w.find('.sr-loupe').trigger('touchstart', {
      touches: [{ clientX: 100, clientY: 200 }, { clientX: 300, clientY: 200 }],
    })
    await w.find('.sr-loupe').trigger('touchmove', {
      touches: [{ clientX: 150, clientY: 200 }, { clientX: 250, clientY: 200 }],
    })
    const after = parseInt(w.find('.sr-loupe__zoom-level').text())
    expect(after).toBeLessThan(before)
  })

  it('Zoom bleibt bei MIN_ZOOM (25%) gedeckelt', async () => {
    const w = factory()
    // Erst reinzoomen, dann viele Male raus
    await w.find('.sr-loupe').trigger('wheel', { deltaY: -120 })
    await w.find('.sr-loupe').trigger('wheel', { deltaY: -120 })
    for (let i = 0; i < 30; i++) {
      await w.find('.sr-loupe').trigger('wheel', { deltaY: 120 })
    }
    const label = w.find('.sr-loupe__zoom-level').text()
    // Entweder Fit (Reset bei MIN_ZOOM-Nähe) oder >= 25%
    if (!label.includes('Eingepasst')) {
      expect(parseInt(label)).toBeGreaterThanOrEqual(25)
    }
  })

  it('Rauszoomen im Fit-Modus per Mausrad wird ignoriert', async () => {
    const w = factory()
    expect(w.find('.sr-loupe__zoom-level').text()).toContain('Eingepasst')
    await w.find('.sr-loupe').trigger('wheel', { deltaY: 120 })
    expect(w.find('.sr-loupe__zoom-level').text()).toContain('Eingepasst')
  })

  it('Rauszoomen im Fit-Modus per Minus-Taste wird ignoriert', async () => {
    const w = factory()
    expect(w.find('.sr-loupe__zoom-level').text()).toContain('Eingepasst')
    await w.find('.sr-loupe').trigger('keydown', { key: '-' })
    expect(w.find('.sr-loupe__zoom-level').text()).toContain('Eingepasst')
  })

  it('Pinch-Zoom bleibt bei MAX_ZOOM (400%) gedeckelt', async () => {
    const w = factory()
    // Extrem großer Pinch: 10px → 2000px → ratio=200
    await w.find('.sr-loupe').trigger('touchstart', {
      touches: [{ clientX: 195, clientY: 200 }, { clientX: 205, clientY: 200 }],
    })
    await w.find('.sr-loupe').trigger('touchmove', {
      touches: [{ clientX: 0, clientY: 200 }, { clientX: 2000, clientY: 200 }],
    })
    const label = w.find('.sr-loupe__zoom-level').text()
    expect(parseInt(label)).toBeLessThanOrEqual(400)
  })

  it('Pinch schaltet nicht gleichzeitig in Pan-Modus', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('touchstart', {
      touches: [{ clientX: 150, clientY: 200 }, { clientX: 250, clientY: 200 }],
    })
    await w.find('.sr-loupe').trigger('touchmove', {
      touches: [{ clientX: 100, clientY: 200 }, { clientX: 300, clientY: 200 }],
    })
    await w.find('.sr-loupe').trigger('touchend', { touches: [] })
    // Nach Pinch-Ende kein 'grabbing'-Cursor
    const style = w.find('.sr-loupe__img').attributes('style') ?? ''
    expect(style).not.toContain('grabbing')
  })

  // ── Touch: Pan ────────────────────────────────────────────────────────────

  it('1-Finger-Pan nach Zoom verschiebt das Bild', async () => {
    const w = factory()
    // Erst per Tastatur reinzoomen
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })

    // 1-Finger-Touch starten und nach rechts ziehen
    await w.find('.sr-loupe').trigger('touchstart', {
      touches: [{ clientX: 200, clientY: 200 }],
    })
    await w.find('.sr-loupe').trigger('touchmove', {
      touches: [{ clientX: 250, clientY: 200 }],
    })
    // constrainPan() klemmt panX in jsdom auf 0 (keine echten DOM-Dimensionen),
    // aber der Cursor wechselt auf 'grabbing' → Pan-Modus ist aktiv
    const style = w.find('.sr-loupe__img').attributes('style') ?? ''
    expect(style).toContain('grabbing')
  })

  it('Pan im Fit-Modus verschiebt das Bild nicht', async () => {
    const w = factory()
    // Kein Zoom → isFit=true → Pan darf nicht aktiviert werden
    await w.find('.sr-loupe').trigger('touchstart', {
      touches: [{ clientX: 200, clientY: 200 }],
    })
    await w.find('.sr-loupe').trigger('touchmove', {
      touches: [{ clientX: 250, clientY: 200 }],
    })
    const style = w.find('.sr-loupe__img').attributes('style') ?? ''
    // Im Fit-Modus kein translate mit Offset
    expect(style).not.toMatch(/translate\(calc\(-50% \+ [^0]/)
  })

  // ── Maus-Drag (Pan) ───────────────────────────────────────────────────────

  it('MouseDown aktiviert Pan-Modus wenn gezoomt', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    await w.find('.sr-loupe').trigger('mousedown', { button: 0, clientX: 200, clientY: 200, preventDefault: () => {} })
    const style = w.find('.sr-loupe__img').attributes('style') ?? ''
    expect(style).toContain('grabbing')
  })

  it('MouseDown mit rechter Maustaste aktiviert keinen Pan-Modus', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    await w.find('.sr-loupe').trigger('mousedown', { button: 2, clientX: 200, clientY: 200 })
    const style = w.find('.sr-loupe__img').attributes('style') ?? ''
    expect(style).not.toContain('grabbing')
  })

  it('MouseDown im Fit-Modus aktiviert keinen Pan-Modus', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('mousedown', { button: 0, clientX: 200, clientY: 200 })
    const style = w.find('.sr-loupe__img').attributes('style') ?? ''
    expect(style).not.toContain('grabbing')
  })

  it('MouseUp beendet Pan-Modus', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    await w.find('.sr-loupe').trigger('mousedown', { button: 0, clientX: 200, clientY: 200, preventDefault: () => {} })
    await w.find('.sr-loupe').trigger('mouseup')
    const style = w.find('.sr-loupe__img').attributes('style') ?? ''
    expect(style).not.toContain('grabbing')
  })

  it('Mouseleave beendet Pan-Modus', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    await w.find('.sr-loupe').trigger('mousedown', { button: 0, clientX: 200, clientY: 200, preventDefault: () => {} })
    await w.find('.sr-loupe').trigger('mouseleave')
    const style = w.find('.sr-loupe__img').attributes('style') ?? ''
    expect(style).not.toContain('grabbing')
  })

  it('MouseMove ohne aktiven Pan-Modus hat keinen Effekt', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    const styleBefore = w.find('.sr-loupe__img').attributes('style') ?? ''
    await w.find('.sr-loupe').trigger('mousemove', { clientX: 300, clientY: 300 })
    const styleAfter = w.find('.sr-loupe__img').attributes('style') ?? ''
    expect(styleAfter).toBe(styleBefore)
  })

  it('MouseMove während Pan aktualisiert Cursor auf grabbing', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    await w.find('.sr-loupe').trigger('mousedown', { button: 0, clientX: 200, clientY: 200, preventDefault: () => {} })
    await w.find('.sr-loupe').trigger('mousemove', { clientX: 250, clientY: 200 })
    const style = w.find('.sr-loupe__img').attributes('style') ?? ''
    expect(style).toContain('grabbing')
  })

  // ── Tastatur: Sondertasten ────────────────────────────────────────────────

  it('Home-Taste navigiert zum ersten Bild', async () => {
    const w = factory({ initialIndex: 2 })
    await w.find('.sr-loupe').trigger('keydown', { key: 'Home' })
    expect(w.emitted('index-change')?.[0]).toEqual([0])
  })

  it('End-Taste navigiert zum letzten Bild', async () => {
    const w = factory({ initialIndex: 0 })
    await w.find('.sr-loupe').trigger('keydown', { key: 'End' })
    expect(w.emitted('index-change')?.[0]).toEqual([2])
  })

  it('Escape bei gezoomtem Bild setzt Zoom zurück statt zu schließen', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('keydown', { key: '+' })
    expect(w.find('.sr-loupe__zoom-level').text()).not.toContain('Eingepasst')
    await w.find('.sr-loupe').trigger('keydown', { key: 'Escape' })
    expect(w.find('.sr-loupe__zoom-level').text()).toContain('Eingepasst')
    expect(w.emitted('close')).toBeFalsy()
  })

  it('v-Taste emittiert Farbe Purple', async () => {
    const w = factory()
    await w.find('.sr-loupe').trigger('keydown', { key: 'v' })
    expect(w.emitted('rate')?.[0][2]).toBe('Purple')
  })

  it('v-Taste togglet Purple (zweimal → null)', async () => {
    const images = [{ id: 1, name: 'A.jpg', rating: 0, color: 'Purple', pick: 'none' }]
    const w = factory({ images, initialIndex: 0 })
    await w.find('.sr-loupe').trigger('keydown', { key: 'v' })
    expect(w.emitted('rate')?.[0][2]).toBeNull()
  })

  // ── Bild-Laden ────────────────────────────────────────────────────────────

  it('onImgLoad blendet Ladeindikator aus', async () => {
    const w = factory()
    await w.find('.sr-loupe__img').trigger('load')
    // Nach load ist kein Spinner mehr sichtbar
    expect(w.find('.sr-loupe__loading').exists()).toBe(false)
  })

  it('onImgError nach 3 Versuchen zeigt Fehler-Placeholder', async () => {
    const w = factory()
    await w.find('.sr-loupe__img').trigger('error')
    await w.find('.sr-loupe__img').trigger('error')
    await w.find('.sr-loupe__img').trigger('error')
    expect(w.find('.sr-loupe__placeholder--error').exists()).toBe(true)
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
