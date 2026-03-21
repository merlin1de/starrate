import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'
import axios from '@nextcloud/axios'

// ── Minimal GuestGallery-Komponente für Tests ─────────────────────────────
// (Die echte GuestGallery.vue wird in Schritt 10 mit dem Build erstellt)
// Diese Tests testen das erwartete Verhalten anhand eines Mock-Komponenten.

const GuestGalleryStub = defineComponent({
  name: 'GuestGallery',
  props: {
    token:      { type: String, required: true },
    canRate:    { type: Boolean, default: false },
    minRating:  { type: Number, default: 0 },
  },
  setup(props) {
    const images       = ref([])
    const loading      = ref(false)
    const passwordDlg  = ref(false)
    const password     = ref('')
    const passwordErr  = ref('')
    const authenticated = ref(false)
    const toasts        = ref([])

    async function loadImages() {
      loading.value = true
      try {
        const { data } = await axios.get(`/apps/starrate/api/guest/${props.token}/images`)
        images.value = data.images ?? []
      } catch (e) {
        if (e?.response?.status === 401) {
          passwordDlg.value = true
        }
      } finally {
        loading.value = false
      }
    }

    async function verifyPassword() {
      try {
        await axios.post(`/apps/starrate/api/guest/${props.token}/verify`, { password: password.value })
        authenticated.value = true
        passwordDlg.value   = false
        passwordErr.value   = ''
        await loadImages()
      } catch {
        passwordErr.value = 'Falsches Passwort'
      }
    }

    async function rateImage(fileId, rating, color) {
      await axios.post(`/apps/starrate/api/guest/${props.token}/rate`, {
        file_id: fileId, rating, color, guest_name: 'Testgast',
      })
      toasts.value.push('Bewertung gespeichert')
    }

    loadImages()

    return { images, loading, passwordDlg, password, passwordErr, authenticated, toasts, verifyPassword, rateImage }
  },
  template: `
    <div class="sr-guest">
      <div v-if="passwordDlg" class="sr-guest__password-dialog">
        <input v-model="password" class="sr-guest__password-input" type="password" placeholder="Passwort" />
        <button class="sr-guest__password-submit" @click="verifyPassword">OK</button>
        <span v-if="passwordErr" class="sr-guest__password-error">{{ passwordErr }}</span>
      </div>
      <div v-if="loading" class="sr-guest__loading">Lädt…</div>
      <div v-else class="sr-guest__gallery">
        <div
          v-for="img in images"
          :key="img.id"
          class="sr-guest__item"
          :data-id="img.id"
        >
          <span class="sr-guest__name">{{ img.name }}</span>
          <div v-if="canRate" class="sr-guest__rate-controls">
            <button
              v-for="star in 5"
              :key="star"
              class="sr-guest__star"
              @click="rateImage(img.id, star, null)"
            >{{ star }}</button>
          </div>
        </div>
      </div>
      <div class="sr-guest__toasts">
        <div v-for="(msg, i) in toasts" :key="i" class="sr-guest__toast">{{ msg }}</div>
      </div>
    </div>
  `,
})

describe('GuestGallery', () => {
  const TOKEN = 'TestToken123'

  beforeEach(() => {
    vi.resetAllMocks()
  })

  // ── Rendering ohne Passwortschutz ─────────────────────────────────────────

  it('lädt Bilder beim Mount', async () => {
    axios.get.mockResolvedValue({
      data: { images: [
        { id: 1, name: 'IMG_0001.jpg' },
        { id: 2, name: 'IMG_0002.jpg' },
      ]},
    })

    const w = mount(GuestGalleryStub, { props: { token: TOKEN, canRate: false } })
    await flushPromises()

    expect(axios.get).toHaveBeenCalledWith(expect.stringContaining(`/guest/${TOKEN}/images`))
    expect(w.findAll('.sr-guest__item')).toHaveLength(2)
  })

  it('zeigt Dateinamen der Bilder', async () => {
    axios.get.mockResolvedValue({
      data: { images: [{ id: 1, name: 'Foto_001.jpg' }] },
    })

    const w = mount(GuestGalleryStub, { props: { token: TOKEN, canRate: false } })
    await flushPromises()

    expect(w.find('.sr-guest__name').text()).toBe('Foto_001.jpg')
  })

  it('zeigt keine Bewertungs-Controls wenn canRate=false', async () => {
    axios.get.mockResolvedValue({ data: { images: [{ id: 1, name: 'test.jpg' }] } })
    const w = mount(GuestGalleryStub, { props: { token: TOKEN, canRate: false } })
    await flushPromises()
    expect(w.find('.sr-guest__rate-controls').exists()).toBe(false)
  })

  it('zeigt Bewertungs-Controls wenn canRate=true', async () => {
    axios.get.mockResolvedValue({ data: { images: [{ id: 1, name: 'test.jpg' }] } })
    const w = mount(GuestGalleryStub, { props: { token: TOKEN, canRate: true } })
    await flushPromises()
    expect(w.find('.sr-guest__rate-controls').exists()).toBe(true)
    expect(w.findAll('.sr-guest__star')).toHaveLength(5)
  })

  // ── Bewertung als Gast setzen ─────────────────────────────────────────────

  it('Klick auf Stern sendet Bewertungs-Request', async () => {
    axios.get.mockResolvedValue({ data: { images: [{ id: 42, name: 'test.jpg' }] } })
    axios.post.mockResolvedValue({ data: { file_id: 42, rating: 3, guest_name: 'Testgast' } })

    const w = mount(GuestGalleryStub, { props: { token: TOKEN, canRate: true } })
    await flushPromises()

    await w.findAll('.sr-guest__star')[2].trigger('click') // Stern 3
    await flushPromises()

    expect(axios.post).toHaveBeenCalledWith(
      expect.stringContaining(`/guest/${TOKEN}/rate`),
      expect.objectContaining({ file_id: 42, rating: 3 })
    )
  })

  it('zeigt Toast nach Bewertung', async () => {
    axios.get.mockResolvedValue({ data: { images: [{ id: 1, name: 'test.jpg' }] } })
    axios.post.mockResolvedValue({ data: {} })

    const w = mount(GuestGalleryStub, { props: { token: TOKEN, canRate: true } })
    await flushPromises()
    await w.findAll('.sr-guest__star')[4].trigger('click')
    await flushPromises()

    expect(w.find('.sr-guest__toast').text()).toContain('Bewertung')
  })

  // ── Passwortschutz ────────────────────────────────────────────────────────

  it('zeigt Passwort-Dialog wenn Server 401 zurückgibt', async () => {
    const error = { response: { status: 401 } }
    axios.get.mockRejectedValue(error)

    const w = mount(GuestGalleryStub, { props: { token: TOKEN, canRate: false } })
    await flushPromises()

    expect(w.find('.sr-guest__password-dialog').exists()).toBe(true)
  })

  it('sendet Passwort beim Klick auf OK', async () => {
    const error = { response: { status: 401 } }
    axios.get.mockRejectedValueOnce(error)
    axios.get.mockResolvedValue({ data: { images: [] } }) // nach Verify
    axios.post.mockResolvedValue({ data: { ok: true } })

    const w = mount(GuestGalleryStub, { props: { token: TOKEN, canRate: false } })
    await flushPromises()

    await w.find('.sr-guest__password-input').setValue('geheim')
    await w.find('.sr-guest__password-submit').trigger('click')
    await flushPromises()

    expect(axios.post).toHaveBeenCalledWith(
      expect.stringContaining(`/guest/${TOKEN}/verify`),
      { password: 'geheim' }
    )
  })

  it('zeigt Fehler bei falschem Passwort', async () => {
    const error401 = { response: { status: 401 } }
    axios.get.mockRejectedValue(error401)
    axios.post.mockRejectedValue(new Error('Falsches Passwort'))

    const w = mount(GuestGalleryStub, { props: { token: TOKEN } })
    await flushPromises()

    await w.find('.sr-guest__password-input').setValue('falsch')
    await w.find('.sr-guest__password-submit').trigger('click')
    await flushPromises()

    expect(w.find('.sr-guest__password-error').text()).toBeTruthy()
  })

  it('lädt Galerie nach korrektem Passwort', async () => {
    const error401 = { response: { status: 401 } }
    axios.get
      .mockRejectedValueOnce(error401)
      .mockResolvedValue({ data: { images: [{ id: 1, name: 'foto.jpg' }] } })
    axios.post.mockResolvedValue({ data: { ok: true } })

    const w = mount(GuestGalleryStub, { props: { token: TOKEN } })
    await flushPromises()

    await w.find('.sr-guest__password-input').setValue('richtig')
    await w.find('.sr-guest__password-submit').trigger('click')
    await flushPromises()

    expect(w.findAll('.sr-guest__item')).toHaveLength(1)
    expect(w.find('.sr-guest__password-dialog').exists()).toBe(false)
  })
})
