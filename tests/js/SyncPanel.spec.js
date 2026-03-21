import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SyncPanel from '../../src/components/SyncPanel.vue'
import axios from '@nextcloud/axios'

const sampleMappings = [
  {
    id: 1, nc_path: '/Fotos/2024', local_path: '/Users/foto/2024',
    direction: 'bidirectional', last_sync: null, status: 'never', log: [],
  },
  {
    id: 2, nc_path: '/Fotos/2023', local_path: '/Users/foto/2023',
    direction: 'nc_to_lr', last_sync: 1700000000, status: 'ok', log: ['[2023-11-14 12:00:00] NC→LR: IMG_0001.jpg → IMG_0001.xmp (★4, Red)'],
  },
]

const factory = () => mount(SyncPanel, {
  global: { stubs: { Teleport: true } },
})

describe('SyncPanel', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    axios.get.mockResolvedValue({ data: { mappings: sampleMappings } })
    axios.post.mockResolvedValue({ data: { synced: 3, skipped: 1, errors: 0, log: ['NC→LR: IMG_0001.jpg → IMG_0001.xmp'] } })
    axios.put.mockResolvedValue({ data: { mapping: { ...sampleMappings[0], nc_path: '/Fotos/neu' } } })
    axios.delete.mockResolvedValue({ data: {} })
  })

  // ── Laden ─────────────────────────────────────────────────────────────────

  it('lädt Zuordnungen beim Mount', async () => {
    const w = factory()
    await flushPromises()
    expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('/sync/mappings'))
    expect(w.findAll('.sr-sync__item:not(.sr-sync__item--skeleton)')).toHaveLength(2)
  })

  it('zeigt Leer-Zustand wenn keine Zuordnungen', async () => {
    axios.get.mockResolvedValue({ data: { mappings: [] } })
    const w = factory()
    await flushPromises()
    expect(w.find('.sr-sync__empty').exists()).toBe(true)
  })

  // ── Status-Anzeige ────────────────────────────────────────────────────────

  it('zeigt Status-Dots für alle Zuordnungen', async () => {
    const w = factory()
    await flushPromises()
    expect(w.findAll('.sr-sync__status-dot')).toHaveLength(2)
  })

  it('zeigt ok-Status korrekt (grüner Dot)', async () => {
    const w = factory()
    await flushPromises()
    const items = w.findAll('.sr-sync__item:not(.sr-sync__item--skeleton)')
    expect(items[1].classes()).toContain('sr-sync__item--ok')
  })

  it('zeigt never-Status korrekt', async () => {
    const w = factory()
    await flushPromises()
    const items = w.findAll('.sr-sync__item:not(.sr-sync__item--skeleton)')
    expect(items[0].classes()).toContain('sr-sync__item--never')
  })

  // ── Sync starten ──────────────────────────────────────────────────────────

  it('startet Sync beim Klick auf Sync-Button', async () => {
    const w = factory()
    await flushPromises()
    const syncBtns = w.findAll('.sr-sync__btn--sync')
    await syncBtns[0].trigger('click')
    expect(axios.post).toHaveBeenCalledWith(expect.stringContaining('/sync/run/1'))
  })

  it('aktualisiert Status nach erfolgreichem Sync', async () => {
    const w = factory()
    await flushPromises()
    await w.findAll('.sr-sync__btn--sync')[0].trigger('click')
    await flushPromises()
    const items = w.findAll('.sr-sync__item:not(.sr-sync__item--skeleton)')
    expect(items[0].classes()).toContain('sr-sync__item--ok')
  })

  it('emittiert toast nach Sync', async () => {
    const w = factory()
    await flushPromises()
    await w.findAll('.sr-sync__btn--sync')[0].trigger('click')
    await flushPromises()
    expect(w.emitted('toast')).toBeTruthy()
    expect(w.emitted('toast')[0][0]).toContain('Sync abgeschlossen')
  })

  it('deaktiviert Sync-Button während Sync läuft', async () => {
    let resolveFn
    axios.post.mockReturnValue(new Promise(r => { resolveFn = r }))
    const w = factory()
    await flushPromises()
    const btn = w.findAll('.sr-sync__btn--sync')[0]
    await btn.trigger('click')
    await w.vm.$nextTick()
    expect(btn.attributes('disabled')).toBeDefined()
    resolveFn({ data: { synced: 0, skipped: 0, errors: 0, log: [] } })
  })

  // ── Log ───────────────────────────────────────────────────────────────────

  it('öffnet Log beim Klick auf Log-Button', async () => {
    const w = factory()
    await flushPromises()
    const logBtns = w.findAll('.sr-sync__btn--log')
    await logBtns[1].trigger('click') // zweite Zuordnung hat Log
    await w.vm.$nextTick()
    expect(w.find('.sr-sync__log').exists()).toBe(true)
  })

  it('schließt Log bei nochmaligem Klick', async () => {
    const w = factory()
    await flushPromises()
    const logBtn = w.findAll('.sr-sync__btn--log')[1]
    await logBtn.trigger('click')
    await logBtn.trigger('click')
    await w.vm.$nextTick()
    expect(w.find('.sr-sync__log').exists()).toBe(false)
  })

  // ── Dialog: Hinzufügen ────────────────────────────────────────────────────

  it('öffnet Dialog beim Klick auf "Zuordnung hinzufügen"', async () => {
    const w = factory()
    await flushPromises()
    await w.find('.sr-sync__add-btn').trigger('click')
    expect(w.find('.sr-dialog').exists()).toBe(true)
  })

  it('Speichern-Button ist deaktiviert ohne Pflichtfelder', async () => {
    const w = factory()
    await flushPromises()
    await w.find('.sr-sync__add-btn').trigger('click')
    const saveBtn = w.find('.sr-dialog__btn--save')
    expect(saveBtn.attributes('disabled')).toBeDefined()
  })

  it('Speichern-Button wird aktiv nach Eingabe der Pflichtfelder', async () => {
    const w = factory()
    await flushPromises()
    await w.find('.sr-sync__add-btn').trigger('click')
    await w.findAll('.sr-dialog__input')[0].setValue('/Fotos/Neu')
    await w.findAll('.sr-dialog__input')[1].setValue('/lokaler/pfad')
    const saveBtn = w.find('.sr-dialog__btn--save')
    expect(saveBtn.attributes('disabled')).toBeUndefined()
  })

  it('sendet POST beim Speichern einer neuen Zuordnung', async () => {
    axios.post.mockResolvedValue({
      data: { mapping: { id: 3, nc_path: '/neu', local_path: '/lokal', direction: 'bidirectional', last_sync: null, status: 'never', log: [] } },
    })
    const w = factory()
    await flushPromises()
    await w.find('.sr-sync__add-btn').trigger('click')
    await w.findAll('.sr-dialog__input')[0].setValue('/neu')
    await w.findAll('.sr-dialog__input')[1].setValue('/lokal')
    await w.find('.sr-dialog__btn--save').trigger('click')
    await flushPromises()
    expect(axios.post).toHaveBeenCalledWith(
      expect.stringContaining('/sync/mappings'),
      expect.objectContaining({ nc_path: '/neu', local_path: '/lokal' })
    )
  })

  it('schließt Dialog nach erfolgreichem Speichern', async () => {
    axios.post.mockResolvedValue({
      data: { mapping: { id: 3, nc_path: '/neu', local_path: '/lokal', direction: 'bidirectional', last_sync: null, status: 'never', log: [] } },
    })
    const w = factory()
    await flushPromises()
    await w.find('.sr-sync__add-btn').trigger('click')
    await w.findAll('.sr-dialog__input')[0].setValue('/neu')
    await w.findAll('.sr-dialog__input')[1].setValue('/lokal')
    await w.find('.sr-dialog__btn--save').trigger('click')
    await flushPromises()
    expect(w.find('.sr-dialog').exists()).toBe(false)
  })

  // ── Dialog: Bearbeiten ────────────────────────────────────────────────────

  it('öffnet Edit-Dialog mit vorausgefüllten Werten', async () => {
    const w = factory()
    await flushPromises()
    await w.findAll('.sr-sync__btn--edit')[0].trigger('click')
    const inputs = w.findAll('.sr-dialog__input')
    expect(inputs[0].element.value).toBe('/Fotos/2024')
    expect(inputs[1].element.value).toBe('/Users/foto/2024')
  })

  it('sendet PUT beim Speichern einer bearbeiteten Zuordnung', async () => {
    const w = factory()
    await flushPromises()
    await w.findAll('.sr-sync__btn--edit')[0].trigger('click')
    await w.findAll('.sr-dialog__input')[0].setValue('/Fotos/geaendert')
    await w.find('.sr-dialog__btn--save').trigger('click')
    await flushPromises()
    expect(axios.put).toHaveBeenCalledWith(
      expect.stringContaining('/sync/mappings/1'),
      expect.objectContaining({ nc_path: '/Fotos/geaendert' })
    )
  })

  // ── Löschen ───────────────────────────────────────────────────────────────

  it('sendet DELETE nach Bestätigung', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const w = factory()
    await flushPromises()
    await w.findAll('.sr-sync__btn--delete')[0].trigger('click')
    await flushPromises()
    expect(axios.delete).toHaveBeenCalledWith(expect.stringContaining('/sync/mappings/1'))
  })

  it('sendet kein DELETE ohne Bestätigung', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    const w = factory()
    await flushPromises()
    await w.findAll('.sr-sync__btn--delete')[0].trigger('click')
    expect(axios.delete).not.toHaveBeenCalled()
  })

  it('entfernt Zuordnung aus der Liste nach Löschen', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const w = factory()
    await flushPromises()
    await w.findAll('.sr-sync__btn--delete')[0].trigger('click')
    await flushPromises()
    expect(w.findAll('.sr-sync__item:not(.sr-sync__item--skeleton)')).toHaveLength(1)
  })
})
