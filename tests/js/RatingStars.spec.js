import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import RatingStars from '../../src/components/RatingStars.vue'

describe('RatingStars', () => {
  const factory = (props = {}) => mount(RatingStars, {
    props: { modelValue: 0, interactive: true, ...props },
  })

  // ── Rendering ─────────────────────────────────────────────────────────────

  it('rendert 5 Stern-Buttons', () => {
    const w = factory()
    expect(w.findAll('.sr-stars__star')).toHaveLength(5)
  })

  it('zeigt korrekte Anzahl ausgefüllter Sterne', () => {
    const w = factory({ modelValue: 3 })
    const filled = w.findAll('.sr-stars__star--filled')
    expect(filled).toHaveLength(3)
  })

  it('zeigt Clear-Button versteckt bei Rating 0', () => {
    const w = factory({ modelValue: 0 })
    const btn = w.find('.sr-stars__clear')
    expect(btn.exists()).toBe(true)
    expect(btn.classes()).toContain('sr-stars__clear--hidden')
  })

  it('zeigt Clear-Button sichtbar bei Rating > 0', () => {
    const w = factory({ modelValue: 3 })
    const btn = w.find('.sr-stars__clear')
    expect(btn.exists()).toBe(true)
    expect(btn.classes()).not.toContain('sr-stars__clear--hidden')
  })

  it('ist nicht interaktiv bei interactive=false', () => {
    const w = factory({ interactive: false })
    expect(w.find('.sr-stars--interactive').exists()).toBe(false)
    w.findAll('.sr-stars__star').forEach(btn => {
      expect(btn.attributes('disabled')).toBeDefined()
    })
  })

  // ── Klick-Events ──────────────────────────────────────────────────────────

  it('emittiert update:modelValue beim Klick auf Stern', async () => {
    const w = factory({ modelValue: 0 })
    await w.findAll('.sr-stars__star')[2].trigger('click') // 3. Stern = Rating 3
    expect(w.emitted('update:modelValue')?.[0]).toEqual([3])
    expect(w.emitted('change')?.[0]).toEqual([3])
  })

  it('setzt Rating auf 0 wenn gleicher Stern nochmals geklickt wird', async () => {
    const w = factory({ modelValue: 4 })
    await w.findAll('.sr-stars__star')[3].trigger('click') // Stern 4 → toggle → 0
    expect(w.emitted('update:modelValue')?.[0]).toEqual([0])
  })

  it('Clear-Button emittiert Rating 0', async () => {
    const w = factory({ modelValue: 3 })
    await w.find('.sr-stars__clear').trigger('click')
    expect(w.emitted('update:modelValue')?.[0]).toEqual([0])
  })

  it('emittiert nichts bei Klick wenn nicht interaktiv', async () => {
    const w = factory({ modelValue: 0, interactive: false })
    await w.findAll('.sr-stars__star')[0].trigger('click')
    expect(w.emitted('update:modelValue')).toBeFalsy()
  })

  // ── Hover-Zustand ─────────────────────────────────────────────────────────

  it('setzt hoverRating beim Mouseenter', async () => {
    const w = factory({ modelValue: 0 })
    await w.findAll('.sr-stars__star')[2].trigger('pointerenter', { pointerType: 'mouse' }) // Stern 3
    // Die ersten 3 Sterne sollten den Hover-Zustand haben
    const stars = w.findAll('.sr-stars__star')
    expect(stars[0].classes()).toContain('sr-stars__star--hover')
    expect(stars[1].classes()).toContain('sr-stars__star--hover')
    expect(stars[2].classes()).toContain('sr-stars__star--hover')
  })

  it('setzt hoverRating zurück auf 0 beim Mouseleave', async () => {
    const w = factory({ modelValue: 0 })
    await w.findAll('.sr-stars__star')[2].trigger('pointerenter', { pointerType: 'mouse' })
    await w.trigger('pointerleave', { pointerType: 'mouse' })
    const stars = w.findAll('.sr-stars__star')
    expect(stars[2].classes()).not.toContain('sr-stars__star--hover')
  })

  // ── Tastaturkürzel ────────────────────────────────────────────────────────

  it.each([0, 1, 2, 3, 4, 5])('Taste "%s" setzt Rating %s', async (rating) => {
    const w = factory({ modelValue: 0 })
    await w.trigger('keydown', { key: String(rating) })
    expect(w.emitted('update:modelValue')?.[0]).toEqual([rating])
  })

  it('ignoriert Nicht-Ziffern-Tasten', async () => {
    const w = factory({ modelValue: 2 })
    await w.trigger('keydown', { key: 'a' })
    expect(w.emitted('update:modelValue')).toBeFalsy()
  })

  it('reagiert nicht auf Tastatur wenn nicht interaktiv', async () => {
    const w = factory({ modelValue: 0, interactive: false })
    await w.trigger('keydown', { key: '5' })
    expect(w.emitted('update:modelValue')).toBeFalsy()
  })

  // ── Kompakter Modus ───────────────────────────────────────────────────────

  it('hat compact-Klasse im kompakten Modus', () => {
    const w = factory({ compact: true })
    expect(w.find('.sr-stars--compact').exists()).toBe(true)
  })

  // ── setRating expose ──────────────────────────────────────────────────────

  it('setRating kann von außen aufgerufen werden', async () => {
    const w = factory({ modelValue: 0 })
    w.vm.setRating(5)
    await w.vm.$nextTick()
    expect(w.emitted('update:modelValue')?.[0]).toEqual([5])
  })
})
