import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import ShareModal from '../../src/components/ShareModal.vue'

describe('ShareModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  const factory = (props = {}) => mount(ShareModal, {
    props: { ncPath: '/Fotos/Test', ...props },
    global: { stubs: { Teleport: true } },
  })

  // ── Rendering ─────────────────────────────────────────────────────────────

  it('rendert Formular initial', () => {
    const w = factory()
    expect(w.find('.sr-share-modal__form').exists()).toBe(true)
    expect(w.find('.sr-share-modal__success').exists()).toBe(false)
  })

  it('zeigt Ordner-Pfad als readonly Input', () => {
    const w = factory({ ncPath: '/Fotos/Urlaub' })
    const input = w.find('.sr-share-modal__input--readonly')
    expect(input.element.value).toBe('/Fotos/Urlaub')
    expect(input.attributes('readonly')).toBeDefined()
  })

  it('zeigt Pflichtfeld-Stern bei permissions=rate', () => {
    const w = factory()
    expect(w.find('.sr-share-modal__required').exists()).toBe(true)
  })

  it('zeigt "optional" bei permissions=view', async () => {
    const w = factory()
    await w.findAll('.sr-share-modal__toggle')[0].trigger('click') // view
    expect(w.find('.sr-share-modal__optional').exists()).toBe(true)
  })

  it('Header hat Close-Button', () => {
    const w = factory()
    expect(w.find('.sr-share-modal__close').exists()).toBe(true)
  })

  // ── Permission Toggle ─────────────────────────────────────────────────────

  it('startet mit permissions=rate', () => {
    const w = factory()
    const toggles = w.findAll('.sr-share-modal__toggle')
    expect(toggles[1].classes()).toContain('sr-share-modal__toggle--active')
  })

  it('wechselt zu view per Klick', async () => {
    const w = factory()
    await w.findAll('.sr-share-modal__toggle')[0].trigger('click')
    expect(w.findAll('.sr-share-modal__toggle')[0].classes()).toContain('sr-share-modal__toggle--active')
  })

  // ── Allow Pick Checkbox ───────────────────────────────────────────────────

  it('zeigt Pick-Checkbox bei permissions=rate', () => {
    const w = factory()
    expect(w.find('.sr-share-modal__checkbox').exists()).toBe(true)
  })

  it('versteckt Pick-Checkbox bei permissions=view', async () => {
    const w = factory()
    await w.findAll('.sr-share-modal__toggle')[0].trigger('click') // view
    expect(w.find('.sr-share-modal__checkbox').exists()).toBe(false)
  })

  // ── Passwort ──────────────────────────────────────────────────────────────

  it('Passwort-Feld initial als password-Typ', () => {
    const w = factory()
    expect(w.find('.sr-share-modal__input--pw').attributes('type')).toBe('password')
  })

  it('Eye-Button toggled Passwort-Sichtbarkeit', async () => {
    const w = factory()
    const eye = w.find('.sr-share-modal__pw-eye')
    await eye.trigger('click')
    expect(w.find('.sr-share-modal__input--pw').attributes('type')).toBe('text')
    await eye.trigger('click')
    expect(w.find('.sr-share-modal__input--pw').attributes('type')).toBe('password')
  })

  // ── Validierung ───────────────────────────────────────────────────────────

  it('zeigt Fehler wenn Rate ohne Gast-Name', async () => {
    const w = factory()
    await w.find('.sr-share-modal__form').trigger('submit')
    expect(w.find('.sr-share-modal__error').exists()).toBe(true)
    expect(axios.post).not.toHaveBeenCalled()
  })

  it('kein Fehler bei View ohne Gast-Name', async () => {
    axios.post.mockResolvedValueOnce({ data: { share: { token: 'abc123' } } })
    const w = factory()
    await w.findAll('.sr-share-modal__toggle')[0].trigger('click') // view
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()
    expect(w.find('.sr-share-modal__error').exists()).toBe(false)
    expect(axios.post).toHaveBeenCalled()
  })

  it('Whitespace-Only Name wird als leer behandelt', async () => {
    const w = factory()
    await w.find('.sr-share-modal__input[type="text"]').setValue('   ')
    await w.find('.sr-share-modal__form').trigger('submit')
    expect(w.find('.sr-share-modal__error').exists()).toBe(true)
  })

  // ── Erfolgreicher Create ──────────────────────────────────────────────────

  it('zeigt Erfolgs-View nach Create', async () => {
    axios.post.mockResolvedValueOnce({
      data: { share: { token: 'test-token-123', nc_path: '/Fotos/Test', permissions: 'rate' } },
    })
    const w = factory()
    await w.find('.sr-share-modal__input[type="text"]').setValue('Tester')
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    expect(w.find('.sr-share-modal__success').exists()).toBe(true)
    expect(w.find('.sr-share-modal__form').exists()).toBe(false)
  })

  it('emittiert created-Event nach Erfolg', async () => {
    const share = { token: 'abc', nc_path: '/Fotos/Test', permissions: 'rate' }
    axios.post.mockResolvedValueOnce({ data: { share } })
    const w = factory()
    await w.find('.sr-share-modal__input[type="text"]').setValue('Tester')
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    expect(w.emitted('created')?.[0]).toEqual([share])
  })

  it('Submit-Button zeigt "Erstelle…" während des Speicherns', async () => {
    let resolvePost
    axios.post.mockReturnValueOnce(new Promise(r => { resolvePost = r }))
    const w = factory()
    await w.find('.sr-share-modal__input[type="text"]').setValue('Tester')
    w.find('.sr-share-modal__form').trigger('submit')
    await w.vm.$nextTick()

    const btn = w.find('.sr-share-modal__btn--primary')
    expect(btn.text()).toContain('Erstelle')
    expect(btn.attributes('disabled')).toBeDefined()

    resolvePost({ data: { share: { token: 'x' } } })
    await flushPromises()
  })

  // ── API-Payload ───────────────────────────────────────────────────────────

  it('sendet korrekten Payload mit allen Feldern', async () => {
    axios.post.mockResolvedValueOnce({ data: { share: { token: 'x' } } })
    const w = factory({ ncPath: '/Bilder' })

    await w.find('.sr-share-modal__input[type="text"]').setValue('Anna')
    await w.find('.sr-share-modal__checkbox').setValue(true) // allowPick
    await w.find('.sr-share-modal__select').setValue(3) // minRating
    await w.find('.sr-share-modal__input--pw').setValue('geheim')
    await w.find('input[type="date"]').setValue('2026-12-31')
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    const [url, body] = axios.post.mock.calls[0]
    expect(url).toContain('/apps/starrate/api/share')
    expect(body.nc_path).toBe('/Bilder')
    expect(body.guest_name).toBe('Anna')
    expect(body.permissions).toBe('rate')
    expect(body.allow_pick).toBe(true)
    expect(body.min_rating).toBe(3)
    expect(body.password).toBe('geheim')
    expect(body.expires_at).toBeGreaterThan(0)
  })

  it('sendet allow_pick=false bei permissions=view auch wenn Checkbox war true', async () => {
    axios.post.mockResolvedValueOnce({ data: { share: { token: 'x' } } })
    const w = factory()

    // Erst rate + allowPick, dann zu view wechseln
    await w.find('.sr-share-modal__checkbox').setValue(true)
    await w.findAll('.sr-share-modal__toggle')[0].trigger('click') // view
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    const body = axios.post.mock.calls[0][1]
    expect(body.allow_pick).toBe(false)
  })

  it('sendet kein Passwort wenn leer', async () => {
    axios.post.mockResolvedValueOnce({ data: { share: { token: 'x' } } })
    const w = factory()
    await w.findAll('.sr-share-modal__toggle')[0].trigger('click') // view
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    const body = axios.post.mock.calls[0][1]
    expect(body).not.toHaveProperty('password')
  })

  it('sendet kein expires_at wenn Datum leer', async () => {
    axios.post.mockResolvedValueOnce({ data: { share: { token: 'x' } } })
    const w = factory()
    await w.findAll('.sr-share-modal__toggle')[0].trigger('click')
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    const body = axios.post.mock.calls[0][1]
    expect(body).not.toHaveProperty('expires_at')
  })

  // ── API-Fehler ────────────────────────────────────────────────────────────

  it('zeigt Server-Fehlermeldung', async () => {
    axios.post.mockRejectedValueOnce({
      response: { data: { error: 'Ordner nicht gefunden' } },
    })
    const w = factory()
    await w.find('.sr-share-modal__input[type="text"]').setValue('Test')
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    expect(w.find('.sr-share-modal__error').text()).toContain('Ordner nicht gefunden')
  })

  it('zeigt generische Fehlermeldung bei unbekanntem Fehler', async () => {
    axios.post.mockRejectedValueOnce(new Error('Network Error'))
    const w = factory()
    await w.find('.sr-share-modal__input[type="text"]').setValue('Test')
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    expect(w.find('.sr-share-modal__error').text()).toContain('Fehler')
  })

  it('setzt saving=false nach Fehler', async () => {
    axios.post.mockRejectedValueOnce(new Error())
    const w = factory()
    await w.find('.sr-share-modal__input[type="text"]').setValue('Test')
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    expect(w.find('.sr-share-modal__btn--primary').attributes('disabled')).toBeUndefined()
  })

  // ── Reset / Weiteren Link erstellen ───────────────────────────────────────

  it('"Weiteren Link erstellen" setzt Formular zurück', async () => {
    axios.post.mockResolvedValueOnce({
      data: { share: { token: 'abc', nc_path: '/Test', permissions: 'rate' } },
    })
    const w = factory()
    await w.find('.sr-share-modal__input[type="text"]').setValue('Tester')
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    expect(w.find('.sr-share-modal__success').exists()).toBe(true)
    await w.find('.sr-share-modal__btn--secondary').trigger('click')
    expect(w.find('.sr-share-modal__form').exists()).toBe(true)
    expect(w.find('.sr-share-modal__success').exists()).toBe(false)
  })

  // ── Close Events ──────────────────────────────────────────────────────────

  it('Close-Button emittiert close', async () => {
    const w = factory()
    await w.find('.sr-share-modal__close').trigger('click')
    expect(w.emitted('close')).toBeTruthy()
  })

  it('Abbrechen-Button emittiert close', async () => {
    const w = factory()
    await w.findAll('.sr-share-modal__btn--secondary')[0].trigger('click')
    expect(w.emitted('close')).toBeTruthy()
  })

  it('Overlay-Klick emittiert close', async () => {
    const w = factory()
    await w.find('.sr-share-modal__overlay').trigger('click')
    expect(w.emitted('close')).toBeTruthy()
  })
})
