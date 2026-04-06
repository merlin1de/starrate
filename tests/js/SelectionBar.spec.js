import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import SelectionBar from '../../src/components/SelectionBar.vue'

// Teleport deaktivieren für Tests
const factory = (props = {}) => mount(SelectionBar, {
  props: { count: 3, ...props },
  global: {
    stubs: { Teleport: true },
  },
})

describe('SelectionBar', () => {

  // ── Rendering ─────────────────────────────────────────────────────────────

  it('zeigt Anzahl der ausgewählten Bilder', () => {
    const w = factory({ count: 14 })
    expect(w.find('.sr-selbar__count').text()).toContain('14')
  })

  it('rendert 6 Sterne-Buttons (0–5)', () => {
    const w = factory()
    expect(w.findAll('.sr-selbar__btn--star')).toHaveLength(6)
  })

  it('rendert 5 Farb-Buttons', () => {
    const w = factory()
    expect(w.findAll('.sr-selbar__btn--color')).toHaveLength(5)
  })

  it('rendert einen Farbe-Entfernen-Button', () => {
    const w = factory()
    // Der letzte Button nach den Farb-Buttons (✕)
    const section = w.find('.sr-selbar__section:last-child')
    const buttons = section.findAll('.sr-selbar__btn')
    // 5 Farben + 1 Remove
    expect(buttons).toHaveLength(6)
  })

  it('zeigt Clear-Button', () => {
    const w = factory()
    expect(w.find('.sr-selbar__clear').exists()).toBe(true)
  })

  // ── Bewertung setzen ──────────────────────────────────────────────────────

  it('emittiert rate mit Rating 4 beim Klick auf ★★★★', async () => {
    const w = factory()
    const starBtns = w.findAll('.sr-selbar__btn--star')
    await starBtns[4].trigger('click') // Index 4 = Rating 4
    const emitted = w.emitted('rate')
    expect(emitted).toBeTruthy()
    expect(emitted[0][0]).toBe(4) // rating
    expect(emitted[0][1]).toBeNull() // color
  })

  it('emittiert rate mit Rating 0 beim Klick auf ✕-Stern', async () => {
    const w = factory()
    const starBtns = w.findAll('.sr-selbar__btn--star')
    await starBtns[0].trigger('click') // Index 0 = Rating 0
    expect(w.emitted('rate')[0][0]).toBe(0)
  })

  it('markiert letzten Stern aktiv nach Klick', async () => {
    const w = factory()
    const starBtns = w.findAll('.sr-selbar__btn--star')
    await starBtns[3].trigger('click') // Rating 3
    expect(starBtns[3].classes()).toContain('sr-selbar__btn--active')
  })

  // ── Farbe setzen ──────────────────────────────────────────────────────────

  it('emittiert rate mit Farbe Green beim Klick auf Grün-Button', async () => {
    const w = factory()
    const colorBtns = w.findAll('.sr-selbar__btn--color')
    await colorBtns[2].trigger('click') // Index 2 = Green
    const emitted = w.emitted('rate')
    expect(emitted[0][0]).toBeNull()  // rating
    expect(emitted[0][1]).toBe('Green') // color
  })

  it('emittiert rate mit null-Farbe beim Klick auf Farbe-Entfernen', async () => {
    const w = factory()
    const section = w.find('.sr-selbar__section:last-child')
    const removBtn = section.findAll('.sr-selbar__btn').at(-1) // letzter = ✕
    await removBtn.trigger('click')
    const emitted = w.emitted('rate')
    expect(emitted[0][1]).toBeNull()
  })

  // ── Auswahl aufheben ──────────────────────────────────────────────────────

  it('emittiert clear beim Klick auf X-Button', async () => {
    const w = factory()
    await w.find('.sr-selbar__clear').trigger('click')
    expect(w.emitted('clear')).toBeTruthy()
  })

  // ── Anzahl-Pluralform ─────────────────────────────────────────────────────

  it('zeigt "1 Bild ausgewählt" für count=1', () => {
    const w = factory({ count: 1 })
    expect(w.find('.sr-selbar__count').text()).toContain('1 Bild ausgewählt')
  })

  it('zeigt "5 Bilder ausgewählt" für count=5', () => {
    const w = factory({ count: 5 })
    expect(w.find('.sr-selbar__count').text()).toContain('5 Bilder ausgewählt')
  })

  // ── Edge Cases ────────────────────────────────────────────────────────────

  it('sequenzielle Klicks: Star → Color → Star aktualisiert aktive Klasse', async () => {
    const w = factory()
    const stars = w.findAll('.sr-selbar__btn--star')
    const colors = w.findAll('.sr-selbar__btn--color')

    await stars[3].trigger('click') // Rating 3
    expect(stars[3].classes()).toContain('sr-selbar__btn--active')

    await colors[0].trigger('click') // Red
    expect(colors[0].classes()).toContain('sr-selbar__btn--active')

    await stars[5].trigger('click') // Rating 5
    expect(stars[5].classes()).toContain('sr-selbar__btn--active')
    // Stern 3 nicht mehr aktiv
    expect(stars[3].classes()).not.toContain('sr-selbar__btn--active')
  })

  it('verschiedene Farben hintereinander — nur letzte aktiv', async () => {
    const w = factory()
    const colors = w.findAll('.sr-selbar__btn--color')

    await colors[0].trigger('click') // Red
    await colors[2].trigger('click') // Green
    expect(colors[0].classes()).not.toContain('sr-selbar__btn--active')
    expect(colors[2].classes()).toContain('sr-selbar__btn--active')
  })

  it('alle 5 Ratings (1–5) emittieren korrekt', async () => {
    const w = factory()
    const stars = w.findAll('.sr-selbar__btn--star')
    for (let i = 1; i <= 5; i++) {
      await stars[i].trigger('click')
      const emitted = w.emitted('rate')
      const last = emitted[emitted.length - 1]
      expect(last[0]).toBe(i)
      expect(last[1]).toBeNull()
    }
  })

  it('alle 5 Farben emittieren korrekt', async () => {
    const expectedColors = ['Red', 'Yellow', 'Green', 'Blue', 'Purple']
    const w = factory()
    const colorBtns = w.findAll('.sr-selbar__btn--color')

    for (let i = 0; i < 5; i++) {
      await colorBtns[i].trigger('click')
      const emitted = w.emitted('rate')
      const last = emitted[emitted.length - 1]
      expect(last[0]).toBeNull()
      expect(last[1]).toBe(expectedColors[i])
    }
  })

  it('große Anzahl wird korrekt angezeigt', () => {
    const w = factory({ count: 999 })
    expect(w.find('.sr-selbar__count').text()).toContain('999')
  })
})
