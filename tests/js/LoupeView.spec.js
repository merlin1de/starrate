import { describe, it, expect, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import LoupeView from '../../src/components/LoupeView.vue'

const images = [
  { id: 1, name: 'a.jpg', rating: 0, color: null, pick: 'none' },
  { id: 2, name: 'b.jpg', rating: 0, color: null, pick: 'none' },
]

function factory(props = {}) {
  return mount(LoupeView, {
    props: {
      images,
      initialIndex: 0,
      previewUrlFn: (id) => `/preview/${id}`,
      ...props,
    },
  })
}

const pt = (x, y) => ({ clientX: x, clientY: y })

describe('LoupeView Download-Button', () => {
  let wrapper

  afterEach(() => wrapper?.unmount())

  it('zeigt den Download-Button wenn canDownload=true', () => {
    wrapper = factory({ canDownload: true })
    expect(wrapper.find('.sr-loupe__download').exists()).toBe(true)
  })

  it('versteckt den Download-Button wenn canDownload=false (Default)', () => {
    wrapper = factory()
    expect(wrapper.find('.sr-loupe__download').exists()).toBe(false)
  })

  it('emittiert "download" mit dem aktuellen Bild beim Klick', async () => {
    wrapper = factory({ canDownload: true })
    await wrapper.find('.sr-loupe__download').trigger('click')
    expect(wrapper.emitted('download')).toBeTruthy()
    expect(wrapper.emitted('download')[0][0]).toMatchObject({ id: 1, name: 'a.jpg' })
  })
})

describe('LoupeView Touch-Gesten', () => {
  let wrapper

  afterEach(() => {
    wrapper?.unmount()
    vi.useRealTimers()
  })

  it('Pinch mit zwei Fingern zoomt rein (committet auf Pinch-Modus)', async () => {
    wrapper = factory()
    const el = wrapper.find('.sr-loupe')
    expect(wrapper.find('.sr-loupe__zoom-level').text()).toBe('Eingepasst')

    // Zwei Finger 100px auseinander → auf 140px spreizen (ratio 1.4)
    await el.trigger('touchstart', { touches: [pt(100, 100), pt(200, 100)] })
    await el.trigger('touchmove', { touches: [pt(80, 100), pt(220, 100)] })

    expect(wrapper.find('.sr-loupe__zoom-level').text()).toMatch(/%/)
  })

  it('zweiter Finger nach Wandern des ersten würgt den Pinch NICHT mehr ab', async () => {
    wrapper = factory()
    const el = wrapper.find('.sr-loupe')

    // Erst ein Finger, der deutlich wandert (>30px — alter Abbruch-Fall) ...
    await el.trigger('touchstart', { touches: [pt(100, 100)] })
    await el.trigger('touchmove', { touches: [pt(150, 100)] })
    // ... dann kommt der zweite Finger dazu → muss in Pinch-Modus wechseln
    await el.trigger('touchstart', { touches: [pt(150, 100), pt(250, 100)] })
    await el.trigger('touchmove', { touches: [pt(120, 100), pt(280, 100)] })

    expect(wrapper.find('.sr-loupe__zoom-level').text()).toMatch(/%/)
  })

  it('Ein-Finger-Swipe im Fit navigiert weiter', async () => {
    wrapper = factory()
    const el = wrapper.find('.sr-loupe')

    await el.trigger('touchstart', { touches: [pt(300, 100)] })
    await el.trigger('touchend', { touches: [], changedTouches: [pt(100, 110)] })

    expect(wrapper.emitted('index-change')).toBeTruthy()
    expect(wrapper.emitted('index-change')[0][0]).toBe(1)
  })

  // v-show spiegelt sich als inline `display: none` — robuster als isVisible()
  // (jsdom liefert keinen Stylesheet-Computed-Style → isVisible() false-negativ).
  const footerHidden = () =>
    (wrapper.find('.sr-loupe__footer').attributes('style') || '').includes('display: none')

  it('Auto-Hide: Mausrad-Zoom blendet die Leiste nach 3s aus, Fit nicht', async () => {
    vi.useFakeTimers()
    wrapper = factory()

    // Im Fit bleibt die Leiste sichtbar — auch nach Ablauf (kein Hide-Timer)
    vi.advanceTimersByTime(3500)
    await nextTick()
    expect(footerHidden()).toBe(false)

    // Reines Mausrad-Zoom (ohne mousemove) armiert jetzt den Hide-Timer
    await wrapper.find('.sr-loupe').trigger('wheel', { deltaY: -100 })
    expect(footerHidden()).toBe(false)

    vi.advanceTimersByTime(3500)
    await nextTick()
    expect(footerHidden()).toBe(true)
  })

  it('Touch holt die ausgeblendete Leiste zurück', async () => {
    vi.useFakeTimers()
    wrapper = factory()

    await wrapper.find('.sr-loupe').trigger('wheel', { deltaY: -100 })
    vi.advanceTimersByTime(3500)
    await nextTick()
    expect(footerHidden()).toBe(true)

    await wrapper.find('.sr-loupe').trigger('touchstart', { touches: [pt(100, 100)] })
    await nextTick()
    expect(footerHidden()).toBe(false)
  })
})

describe('LoupeView Doppelklick-Zoom', () => {
  let wrapper
  afterEach(() => wrapper?.unmount())

  it('over-zoomt auf einen festen Faktor (formatunabhängig) und toggelt zurück auf Fit', async () => {
    wrapper = factory()
    const el = wrapper.find('.sr-loupe')
    expect(wrapper.find('.sr-loupe__zoom-level').text()).toBe('Eingepasst')

    // Fester Over-Zoom — unabhängig von Bildmaßen (jsdom hat keine), also gleicher
    // Wert für Hoch- wie Querformat. Vorher rechnete naturalWidth/cw → formatabhängig.
    await el.trigger('dblclick')
    expect(wrapper.find('.sr-loupe__zoom-level').text()).toBe('300%')

    // Zweiter Doppelklick → zurück auf Fit
    await el.trigger('dblclick')
    expect(wrapper.find('.sr-loupe__zoom-level').text()).toBe('Eingepasst')
  })

  it('Fit ist die Untergrenze: Rauszoomen unter Fit schnappt auf Eingepasst zurück', async () => {
    wrapper = factory()
    const el = wrapper.find('.sr-loupe')
    const label = () => wrapper.find('.sr-loupe__zoom-level').text()

    // Aus dem Fit heraus rauszoomen (deltaY > 0) bleibt Fit
    await el.trigger('wheel', { deltaY: 100 })
    expect(label()).toBe('Eingepasst')

    // Reinzoomen funktioniert
    await el.trigger('wheel', { deltaY: -100 })
    expect(label()).toMatch(/%/)

    // Mehrfach rauszoomen → schnappt auf Fit, nicht unter Fit
    for (let i = 0; i < 12; i++) await el.trigger('wheel', { deltaY: 100 })
    expect(label()).toBe('Eingepasst')
  })
})
