import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import { defineComponent } from 'vue'
import Gallery from '../../src/views/Gallery.vue'

// ── Stubs ──────────────────────────────────────────────────────────────────────

const GridViewStub = defineComponent({
  name: 'GridView',
  props: {
    images: Array,
    loading: Boolean,
    hasActiveFilter: Boolean,
    currentIndex: Number,
    thumbnailSize: Number,
    gridColumns: [String, Number],
    showFilename: Boolean,
    showRatingInfo: Boolean,
    showColorInfo: Boolean,
    enablePickUi: Boolean,
    thumbnailUrlFn: Function,
  },
  emits: ['rate', 'batch-rate', 'open-loupe', 'selection-change', 'clear-filter'],
  expose: ['clearSelection', 'selectAll', 'selectedIds'],
  setup(_, { expose }) {
    expose({ clearSelection: vi.fn(), selectAll: vi.fn() })
    return () => null
  },
})

const stubs = {
  GridView: GridViewStub,
  FilterBar: { template: '<div />' },
  LoupeView: { template: '<div />' },
  SelectionBar: {
    name: 'SelectionBar',
    props: { count: Number },
    emits: ['rate', 'clear'],
    template: '<div class="selection-bar-stub" />',
  },
  ShareList:   { template: '<div />' },
  ShareModal:  { template: '<div />' },
  ExportModal: { name: 'ExportModal', props: ['images', 'showPickCol'], emits: ['close'], template: '<div class="export-modal-stub" />' },
  Teleport:    true,
}

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: {} }],
  })
}

function makeImages() {
  return [
    { id: 1, name: 'A.jpg', rating: 0, color: null, pick: null },
    { id: 2, name: 'B.jpg', rating: 3, color: 'Red', pick: null },
    { id: 3, name: 'C.jpg', rating: 5, color: 'Blue', pick: 'pick' },
  ]
}

function factory(extraProps = {}) {
  const batchRateFn = vi.fn().mockResolvedValue({})
  const loadImagesFn = vi.fn().mockResolvedValue({ images: makeImages(), folders: [] })

  const w = mount(Gallery, {
    props: {
      guestMode: true,  // überspringt Settings-API-Aufruf
      loadImagesFn,
      batchRateFn,
      ...extraProps,
    },
    global: {
      plugins: [makeRouter()],
      stubs,
    },
  })
  return { w, batchRateFn, loadImagesFn }
}

// ── Hilfsfunktionen ────────────────────────────────────────────────────────────

async function selectImages(w, ids) {
  await w.findComponent(GridViewStub).vm.$emit('selection-change', new Set(ids))
}

async function triggerBatchRate(w, rating, color, pick) {
  await w.findComponent(GridViewStub).vm.$emit('batch-rate', rating, color, pick)
}

async function triggerSelectionBarRate(w, rating, color) {
  await w.findComponent({ name: 'SelectionBar' }).vm.$emit('rate', rating, color)
}

// ──────────────────────────────────────────────────────────────────────────────

describe('Gallery – onBatchRate', () => {

  beforeEach(() => {
    vi.resetAllMocks()
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  // ── Sterne ────────────────────────────────────────────────────────────────

  it('batch-rate: Sterne setzen sendet rating an batchRateFn', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1, 2])
    await triggerBatchRate(w, 4, undefined, undefined)
    vi.advanceTimersByTime(1100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledWith(
      expect.arrayContaining([1, 2]),
      expect.objectContaining({ rating: 4 })
    )
  })

  it('batch-rate: Sterne entfernen (0) sendet rating=0', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1])
    await triggerBatchRate(w, 0, undefined, undefined)
    vi.advanceTimersByTime(1100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledWith(
      [1],
      expect.objectContaining({ rating: 0 })
    )
  })

  it('batch-rate: Sterne ohne Auswahl sendet keinen Request', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    // Keine Auswahl gesetzt
    await triggerBatchRate(w, 3, undefined, undefined)
    vi.advanceTimersByTime(1100)
    await flushPromises()

    expect(batchRateFn).not.toHaveBeenCalled()
  })

  // ── Farben ────────────────────────────────────────────────────────────────

  it('batch-rate: Farbe setzen sendet color an batchRateFn', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1, 3])
    await triggerBatchRate(w, undefined, 'Green', undefined)
    vi.advanceTimersByTime(1100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledWith(
      expect.arrayContaining([1, 3]),
      expect.objectContaining({ color: 'Green' })
    )
  })

  it('batch-rate: Farbe entfernen (null) sendet color=null — Bug-Fix', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [2])
    await triggerBatchRate(w, undefined, null, undefined)
    vi.advanceTimersByTime(1100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledWith(
      [2],
      expect.objectContaining({ color: null })
    )
  })

  it('SelectionBar: Farbe entfernen (X-Button) sendet color=null', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1, 2])
    await triggerSelectionBarRate(w, undefined, null)
    vi.advanceTimersByTime(1100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledWith(
      expect.arrayContaining([1, 2]),
      expect.objectContaining({ color: null })
    )
  })

  it('SelectionBar: Farbe setzen sendet kein rating-Feld', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1])
    await triggerSelectionBarRate(w, undefined, 'Blue')
    vi.advanceTimersByTime(1100)
    await flushPromises()

    const payload = batchRateFn.mock.calls[0][1]
    expect(payload.color).toBe('Blue')
    expect(payload).not.toHaveProperty('rating')
  })

  it('SelectionBar: Sterne setzen sendet kein color-Feld', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1])
    await triggerSelectionBarRate(w, 3, undefined)
    vi.advanceTimersByTime(1100)
    await flushPromises()

    const payload = batchRateFn.mock.calls[0][1]
    expect(payload.rating).toBe(3)
    expect(payload).not.toHaveProperty('color')
  })

  // ── Pick ──────────────────────────────────────────────────────────────────

  it('batch-rate: Pick setzen sendet pick-Feld', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1, 2])
    await triggerBatchRate(w, undefined, undefined, 'pick')
    vi.advanceTimersByTime(1100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledWith(
      expect.arrayContaining([1, 2]),
      expect.objectContaining({ pick: 'pick' })
    )
  })

  it('batch-rate: Reject setzen sendet pick=reject', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [3])
    await triggerBatchRate(w, undefined, undefined, 'reject')
    vi.advanceTimersByTime(1100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledWith([3], expect.objectContaining({ pick: 'reject' }))
  })

  // ── Debounce-Merge ────────────────────────────────────────────────────────

  it('batch-rate: schnelle Stern+Farbe Klicks werden zu einem Request zusammengeführt', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1, 2])
    await triggerBatchRate(w, 3, undefined, undefined)
    await triggerBatchRate(w, undefined, 'Red', undefined)
    vi.advanceTimersByTime(1100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledTimes(1)
    expect(batchRateFn).toHaveBeenCalledWith(
      expect.arrayContaining([1, 2]),
      expect.objectContaining({ rating: 3, color: 'Red' })
    )
  })

  it('batch-rate: Stern+Farbe+Pick werden zu einem Request zusammengeführt', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1, 2, 3])
    await triggerBatchRate(w, 4, undefined, undefined)
    await triggerBatchRate(w, undefined, 'Yellow', undefined)
    await triggerBatchRate(w, undefined, undefined, 'pick')
    vi.advanceTimersByTime(1100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledTimes(1)
    expect(batchRateFn).toHaveBeenCalledWith(
      expect.arrayContaining([1, 2, 3]),
      expect.objectContaining({ rating: 4, color: 'Yellow', pick: 'pick' })
    )
  })

  it('batch-rate: zweiter Klick mit neuer Auswahl aktualisiert fileIds', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1, 2])
    await triggerBatchRate(w, 3, undefined, undefined)
    // Auswahl ändert sich vor dem zweiten Klick
    await selectImages(w, [3])
    await triggerBatchRate(w, undefined, 'Blue', undefined)
    vi.advanceTimersByTime(1100)
    await flushPromises()

    // fileIds muss dem letzten Stand entsprechen
    expect(batchRateFn).toHaveBeenCalledTimes(1)
    expect(batchRateFn).toHaveBeenCalledWith(
      [3],
      expect.objectContaining({ rating: 3, color: 'Blue' })
    )
  })

  it('batch-rate: zwei separate Klicks mit >1s Abstand senden zwei Requests', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1])
    await triggerBatchRate(w, 3, undefined, undefined)
    vi.advanceTimersByTime(1100)
    await flushPromises()

    await triggerBatchRate(w, undefined, 'Red', undefined)
    vi.advanceTimersByTime(1100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledTimes(2)
    expect(batchRateFn).toHaveBeenNthCalledWith(1, [1], expect.objectContaining({ rating: 3 }))
    expect(batchRateFn).toHaveBeenNthCalledWith(2, [1], expect.objectContaining({ color: 'Red' }))
  })

  // ── Optimistisches Update ─────────────────────────────────────────────────

  it('batch-rate: Sterne werden optimistisch im lokalen State gesetzt', async () => {
    const { w } = factory()
    await flushPromises()

    await selectImages(w, [1])
    await triggerBatchRate(w, 5, undefined, undefined)

    // Nach dem optimistischen Update (synchron) ist rating bereits 5
    const grid = w.findComponent(GridViewStub)
    const img = grid.props('images').find(i => i.id === 1)
    expect(img.rating).toBe(5)
  })

  it('batch-rate: Farbe wird optimistisch im lokalen State entfernt', async () => {
    const { w } = factory()
    await flushPromises()

    await selectImages(w, [2]) // Bild 2 hat color='Red'
    await triggerBatchRate(w, undefined, null, undefined)

    const grid = w.findComponent(GridViewStub)
    const img = grid.props('images').find(i => i.id === 2)
    expect(img.color).toBeNull()
  })
})

// ──────────────────────────────────────────────────────────────────────────────

describe('Gallery – Export Modal', () => {
  it('Export-Button ist sichtbar wenn allowExport=true', async () => {
    const { w } = factory({ allowExport: true })
    await flushPromises()
    expect(w.find('[title="Bewertungsliste exportieren"]').exists()).toBe(true)
  })

  it('Export-Button öffnet ExportModal', async () => {
    const { w } = factory({ allowExport: true })
    await flushPromises()
    expect(w.find('.export-modal-stub').exists()).toBe(false)
    await w.find('[title="Bewertungsliste exportieren"]').trigger('click')
    expect(w.find('.export-modal-stub').exists()).toBe(true)
  })

  it('ESC schließt ExportModal', async () => {
    const { w } = factory({ allowExport: true })
    await flushPromises()
    await w.find('[title="Bewertungsliste exportieren"]').trigger('click')
    expect(w.find('.export-modal-stub').exists()).toBe(true)
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await w.vm.$nextTick()
    expect(w.find('.export-modal-stub').exists()).toBe(false)
  })

  it('@close-Event schließt ExportModal', async () => {
    const { w } = factory({ allowExport: true })
    await flushPromises()
    await w.find('[title="Bewertungsliste exportieren"]').trigger('click')
    await w.findComponent({ name: 'ExportModal' }).vm.$emit('close')
    expect(w.find('.export-modal-stub').exists()).toBe(false)
  })
})
