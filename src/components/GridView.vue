<template>
  <div
    class="sr-grid"
    ref="gridEl"
    tabindex="0"
    :style="gridStyle"
    @keydown="onGlobalKeydown"
  >
    <!-- Skeleton-Loader -->
    <template v-if="loading">
      <div v-for="i in skeletonCount" :key="`skel-${i}`" class="sr-grid__item sr-grid__item--skeleton">
        <div class="sr-grid__skeleton-img" />
        <div class="sr-grid__skeleton-bar" />
      </div>
    </template>

    <!-- Bilder -->
    <template v-else>
      <div
        v-for="(image, index) in images"
        :key="image.id"
        class="sr-grid__item"
        :class="{
          'sr-grid__item--selected': isSelected(image.id),
          'sr-grid__item--focused':  focusedIndex === index,
          'sr-grid__item--pick':     image.pick === 'pick',
          'sr-grid__item--reject':   image.pick === 'reject',
        }"
        :data-index="index"
        @click="onItemClick($event, image, index)"
        @dblclick="$emit('open-loupe', image, index)"
      >
        <!-- Thumbnail -->
        <div class="sr-grid__thumb-wrap">
          <img
            v-if="image.thumbLoaded"
            class="sr-grid__thumb"
            :src="image.thumbUrl"
            :alt="image.name"
            loading="lazy"
            draggable="false"
          />
          <div v-else class="sr-grid__thumb-placeholder" />

          <!-- Reject-Overlay -->
          <div v-if="image.pick === 'reject'" class="sr-grid__reject-overlay">
            <span>✕</span>
          </div>

          <!-- Auswahl-Indikator -->
          <div v-if="isSelected(image.id)" class="sr-grid__select-badge">
            <svg viewBox="0 0 24 24" fill="none"><polyline points="20 6 9 17 4 12" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </div>

          <!-- Hover-Overlay: Steuerelemente -->
          <div class="sr-grid__hover-overlay">
            <RatingStars
              :model-value="image.rating"
              :interactive="true"
              :compact="true"
              @change="(r) => $emit('rate', image, r, undefined)"
              @click.stop
            />
            <ColorLabel
              :model-value="image.color"
              :interactive="true"
              :compact="false"
              @change="(c) => $emit('rate', image, undefined, c)"
              @click.stop
            />
          </div>
        </div>

        <!-- Info-Leiste unten -->
        <div class="sr-grid__info">
          <!-- Sterne (linke Seite) -->
          <RatingStars
            v-if="showRatingInfo"
            :model-value="image.rating"
            :interactive="false"
            :compact="true"
            class="sr-grid__info-stars"
          />
          <!-- Dateiname (Mitte) -->
          <span v-if="showFilename" class="sr-grid__info-name" :title="image.name">{{ image.name }}</span>
          <!-- Farbpunkt (rechts) -->
          <span
            v-if="showColorInfo && image.color"
            class="sr-grid__info-color"
            :class="`sr-grid__info-color--${image.color.toLowerCase()}`"
            :title="image.color"
          />
        </div>
      </div>

      <!-- Leer-Zustand -->
      <div v-if="!loading && images.length === 0" class="sr-grid__empty">
        <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
          <rect x="8" y="16" width="48" height="36" rx="4" stroke="#555" stroke-width="2"/>
          <circle cx="22" cy="28" r="4" stroke="#555" stroke-width="2"/>
          <path d="M8 40l12-10 8 8 8-6 12 8" stroke="#555" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <p>{{ t('starrate', 'Keine Bilder in diesem Ordner') }}</p>
        <button
          v-if="hasActiveFilter"
          class="sr-grid__empty-cta"
          type="button"
          @click="$emit('clear-filter')"
        >{{ t('starrate', 'Alle Filter löschen') }}</button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import RatingStars from './RatingStars.vue'
import ColorLabel from './ColorLabel.vue'

const props = defineProps({
  /** Array von Bild-Objekten (von der API) */
  images: {
    type: Array,
    default: () => [],
  },
  /** Lädt gerade */
  loading: {
    type: Boolean,
    default: false,
  },
  /** Aktiver Filter hat Ergebnisse gefiltert */
  hasActiveFilter: {
    type: Boolean,
    default: false,
  },
  /** Aktuell fokussiertes Bild (Lupe-Sync) */
  currentIndex: {
    type: Number,
    default: -1,
  },
  /** Thumbnail-Größe in Pixeln (aus Settings) */
  thumbnailSize: { type: Number,  default: 280 },
  /** Grid-Spalten: 'auto' | '2' | '3' | '4' | '5' | '6' | '8' */
  gridColumns:   { type: String,  default: 'auto' },
  /** Dateiname in der Info-Leiste anzeigen */
  showFilename:      { type: Boolean, default: true },
  /** Sterne in der Info-Leiste anzeigen */
  showRatingInfo:    { type: Boolean, default: true },
  /** Farbpunkt in der Info-Leiste anzeigen */
  showColorInfo:     { type: Boolean, default: true },
})

const emit = defineEmits([
  'rate',             // (image, rating|undefined, color|undefined, pick|undefined)
  'open-loupe',       // (image, index)
  'selection-change', // (selectedIds: Set)
  'clear-filter',     // ()
])

const gridStyle = computed(() => {
  // gridTemplateColumns direkt als Inline-Style – CSS-custom-properties mit repeat()
  // werden in einigen Browsern nicht korrekt in grid-template-columns geparsed.
  if (props.gridColumns !== 'auto') {
    return { gridTemplateColumns: `repeat(${props.gridColumns}, 1fr)` }
  }
  // min() stellt sicher dass auf Mobile mindestens 2 Spalten passen:
  // min(280px, calc(50vw - 16px)) → Desktop: 280px, Mobile 390px: ~179px → 2 Spalten
  return { gridTemplateColumns: `repeat(auto-fill, minmax(min(${props.thumbnailSize}px, calc(50vw - 16px)), 1fr))` }
})

// ─── Zustand ──────────────────────────────────────────────────────────────────

const gridEl       = ref(null)
const focusedIndex = ref(-1)
const selectedIds  = ref(new Set())
const lastClickIdx = ref(-1)
const skeletonCount = 16

// Thumbnail-Cache: überlebt Filter-Wechsel
const thumbCache = ref({})

// ─── Thumbnail-Loading: IntersectionObserver + Concurrency-Queue ─────────────

const THUMB_CONCURRENCY = 5
let thumbObserver = null
let activeLoads   = 0
const loadQueue   = []   // kein ref – wir brauchen keine Reaktivität

function setupThumbObserver() {
  thumbObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return
      const idx   = parseInt(entry.target.dataset.index, 10)
      const image = props.images[idx]
      if (!image || image.thumbLoaded) return
      if (thumbCache.value[image.id]) {
        image.thumbUrl    = thumbCache.value[image.id]
        image.thumbLoaded = true
      } else {
        enqueueThumb(image)
      }
      thumbObserver.unobserve(entry.target)
    })
  }, { rootMargin: '400px 0px' })  // 400px Vorladen
}

function observeAllItems() {
  nextTick(() => {
    if (!gridEl.value || !thumbObserver) return
    const items = gridEl.value.querySelectorAll('.sr-grid__item[data-index]')
    items.forEach(el => {
      const idx = parseInt(el.dataset.index, 10)
      const img = props.images[idx]
      if (img && !img.thumbLoaded) thumbObserver.observe(el)
    })
  })
}

function enqueueThumb(image) {
  if (loadQueue.some(i => i.id === image.id)) return
  loadQueue.push(image)
  drainQueue()
}

function drainQueue() {
  while (activeLoads < THUMB_CONCURRENCY && loadQueue.length > 0) {
    const image = loadQueue.shift()
    if (image.thumbLoaded) continue
    activeLoads++
    loadThumb(image)
  }
}

function loadThumb(image) {
  const sz  = props.thumbnailSize
  const url = generateUrl(`/apps/starrate/api/thumbnail/${image.id}?width=${sz}&height=${sz}`)
  const imgEl = new Image()
  imgEl.onload = () => {
    thumbCache.value[image.id] = url
    const found = props.images.find(i => i.id === image.id)
    if (found) { found.thumbUrl = url; found.thumbLoaded = true }
    activeLoads--
    drainQueue()
  }
  imgEl.onerror = () => {
    const found = props.images.find(i => i.id === image.id)
    if (found) found.thumbLoaded = true
    activeLoads--
    drainQueue()
  }
  imgEl.src = url
}

// Bilder-Array wechselt (Filter / Ordner): Observer + Queue neu aufsetzen
watch(() => props.images, () => {
  loadQueue.length = 0
  thumbObserver?.disconnect()
  observeAllItems()
})

// ─── Auswahl ──────────────────────────────────────────────────────────────────

function isSelected(id) {
  return selectedIds.value.has(id)
}

function onItemClick(event, image, index) {
  if (event.shiftKey && lastClickIdx.value >= 0) {
    // Shift+Klick → Bereich markieren
    const from = Math.min(lastClickIdx.value, index)
    const to   = Math.max(lastClickIdx.value, index)
    for (let i = from; i <= to; i++) {
      selectedIds.value.add(props.images[i].id)
    }
  } else if (event.ctrlKey || event.metaKey) {
    // Strg/Cmd+Klick → einzelnes Bild togglen
    if (selectedIds.value.has(image.id)) {
      selectedIds.value.delete(image.id)
    } else {
      selectedIds.value.add(image.id)
    }
  } else {
    // Normaler Klick → Auswahl aufheben wenn vorhanden, sonst nur Focus setzen
    if (selectedIds.value.size > 0) {
      selectedIds.value.clear()
      focusedIndex.value = index
      lastClickIdx.value = index
      emit('selection-change', new Set())
      return
    }
    focusedIndex.value = index
    lastClickIdx.value = index
    return  // kein selection-change bei reinem Focus
  }

  lastClickIdx.value = index
  emit('selection-change', new Set(selectedIds.value))
}

function clearSelection() {
  selectedIds.value.clear()
  emit('selection-change', new Set())
}

function selectAll() {
  props.images.forEach(img => selectedIds.value.add(img.id))
  emit('selection-change', new Set(selectedIds.value))
}

// ─── Tastatur ────────────────────────────────────────────────────────────────

function onGlobalKeydown(e) {
  const idx = focusedIndex.value

  switch (e.key) {
    // Pos1 / Ende
    case 'Home':
      e.preventDefault()
      if (props.images.length > 0) {
        focusedIndex.value = 0
        lastClickIdx.value  = 0
        scrollItemIntoView(0)
      }
      break
    case 'End':
      e.preventDefault()
      if (props.images.length > 0) {
        const last = props.images.length - 1
        focusedIndex.value = last
        lastClickIdx.value  = last
        scrollItemIntoView(last)
      }
      break

    // Navigation (Shift+Arrow = Mehrfachauswahl)
    case 'ArrowRight':
      e.preventDefault()
      moveFocus(1, e.shiftKey)
      break
    case 'ArrowLeft':
      e.preventDefault()
      moveFocus(-1, e.shiftKey)
      break
    case 'ArrowDown':
      e.preventDefault()
      moveFocus(columnsEstimate(), e.shiftKey)
      break
    case 'ArrowUp':
      e.preventDefault()
      moveFocus(-columnsEstimate(), e.shiftKey)
      break

    // Enter / Doppelklick → Lupenansicht
    case 'Enter':
      if (idx >= 0 && idx < props.images.length) {
        emit('open-loupe', props.images[idx], idx)
      }
      break

    // Sternebewertung (0–5)
    case '0': case '1': case '2':
    case '3': case '4': case '5':
      if (idx >= 0 && !e.shiftKey) {
        e.preventDefault()
        const img = props.images[idx]
        if (img) emit('rate', img, parseInt(e.key), undefined)
      }
      break

    // Farben (6=Rot, 7=Gelb, 8=Grün, 9=Blau, V=Lila) — Toggle
    case '6': case '7': case '8': case '9': {
      e.preventDefault()
      const colorMap = { '6': 'Red', '7': 'Yellow', '8': 'Green', '9': 'Blue' }
      if (idx >= 0) {
        const img = props.images[idx]
        const c = colorMap[e.key]
        if (img) emit('rate', img, undefined, img.color === c ? null : c)
      }
      break
    }
    case 'v': case 'V':
      if (idx >= 0 && !e.ctrlKey && !e.metaKey) {
        e.preventDefault()
        const img = props.images[idx]
        if (img) emit('rate', img, undefined, img.color === 'Purple' ? null : 'Purple')
      }
      break

    // P = Pick, X = Reject
    case 'p': case 'P':
      if (idx >= 0) {
        e.preventDefault()
        const img = props.images[idx]
        if (img) emit('rate', img, undefined, undefined, img.pick === 'pick' ? 'none' : 'pick')
      }
      break
    case 'x': case 'X':
      if (idx >= 0) {
        e.preventDefault()
        const img = props.images[idx]
        if (img) emit('rate', img, undefined, undefined, img.pick === 'reject' ? 'none' : 'reject')
      }
      break

    // Strg+A = alle auswählen
    case 'a':
    case 'A':
      if (e.ctrlKey || e.metaKey) {
        e.preventDefault()
        selectAll()
      }
      break

    // Escape = Auswahl aufheben
    case 'Escape':
      clearSelection()
      break
  }
}

function moveFocus(delta, extend = false) {
  if (props.images.length === 0) return

  // Kein Focus gesetzt → starte beim zuletzt bekannten Index oder 0
  if (focusedIndex.value < 0) {
    focusedIndex.value = (props.currentIndex >= 0 && props.currentIndex < props.images.length)
      ? props.currentIndex : 0
    lastClickIdx.value = focusedIndex.value
  }

  const newIdx = Math.max(0, Math.min(props.images.length - 1, focusedIndex.value + delta))
  if (newIdx === focusedIndex.value) return

  focusedIndex.value = newIdx
  scrollItemIntoView(newIdx)

  if (extend) {
    // Shift+Arrow: Bereich vom Anker (lastClickIdx) bis newIdx selektieren
    const anchor = lastClickIdx.value >= 0 ? lastClickIdx.value : newIdx
    selectedIds.value.clear()
    const from = Math.min(anchor, newIdx)
    const to   = Math.max(anchor, newIdx)
    for (let i = from; i <= to; i++) {
      selectedIds.value.add(props.images[i].id)
    }
    emit('selection-change', new Set(selectedIds.value))
  } else {
    lastClickIdx.value = newIdx
  }
}

function columnsEstimate() {
  if (!gridEl.value) return 4
  return Math.max(1, Math.floor(gridEl.value.offsetWidth / props.thumbnailSize))
}

function scrollItemIntoView(index) {
  nextTick(() => {
    const el = gridEl.value?.querySelector(`[data-index="${index}"]`)
    el?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' })
  })
}

// ─── Sync mit Lupenansicht ────────────────────────────────────────────────────

watch(() => props.currentIndex, idx => {
  if (idx >= 0) {
    focusedIndex.value = idx
    scrollItemIntoView(idx)
  }
}, { immediate: true })   // immediate: focusedIndex beim Mount sofort setzen

// ─── Autofocus beim Mount ─────────────────────────────────────────────────────

onMounted(() => {
  setupThumbObserver()
  observeAllItems()
  nextTick(() => gridEl.value?.focus({ preventScroll: true }))
})

onUnmounted(() => {
  thumbObserver?.disconnect()
})

// ─── Expose für SelectionBar ─────────────────────────────────────────────────

defineExpose({ clearSelection, selectAll, selectedIds })
</script>

<style scoped>
.sr-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); /* Fallback; wird via :style überschrieben */
  gap: 6px;
  padding: 8px;
  outline: none;
  align-content: start;
  /* max-height unabhängig von der Elternkette – NC-Header 50px + Breadcrumb ~36px + Filterbar ~62px + Puffer */
  max-height: calc(100vh - 160px);
  overflow-y: auto;
}

/* ── Item ─────────────────────────────────────────────────────────────────── */
.sr-grid__item {
  position: relative;
  background: #1e1e2e;
  border-radius: 4px;
  overflow: hidden;
  cursor: pointer;
  border: 2px solid transparent;
  transition: border-color 100ms ease, box-shadow 100ms ease;
  user-select: none;
}

.sr-grid__item:hover {
  border-color: rgba(255,255,255,0.15);
}

.sr-grid__item--selected {
  border-color: #e94560 !important;
  box-shadow: 0 0 0 1px #e94560;
}

.sr-grid__item--focused {
  box-shadow: 0 0 0 2px #e94560aa;
}

.sr-grid__item--reject {
  opacity: 0.45;
}

/* ── Thumbnail ────────────────────────────────────────────────────────────── */
.sr-grid__thumb-wrap {
  position: relative;
  width: 100%;
  padding-top: 75%; /* 4:3 Aspektverhältnis */
  background: #111;
  overflow: hidden;
}

.sr-grid__thumb {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transition: transform 200ms ease;
}

.sr-grid__item:hover .sr-grid__thumb {
  transform: scale(1.02);
}

.sr-grid__thumb-placeholder {
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, #1e1e2e 25%, #2a2a3e 50%, #1e1e2e 75%);
  background-size: 400% 400%;
  animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
  0%   { background-position: 100% 50%; }
  100% { background-position: 0% 50%; }
}

/* ── Overlays ─────────────────────────────────────────────────────────────── */
.sr-grid__reject-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0,0,0,0.4);
  color: #e94560;
  font-size: 3rem;
  font-weight: bold;
  pointer-events: none;
}

.sr-grid__select-badge {
  position: absolute;
  top: 6px;
  left: 6px;
  width: 22px;
  height: 22px;
  background: #e94560;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
  z-index: 2;
}

.sr-grid__select-badge svg {
  width: 14px;
  height: 14px;
}

.sr-grid__hover-overlay {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  padding: 8px 8px 6px;
  background: linear-gradient(to top, rgba(0,0,0,0.8) 0%, transparent 100%);
  display: flex;
  align-items: center;
  gap: 8px;
  opacity: 0;
  transform: translateY(4px);
  transition: opacity 150ms ease, transform 150ms ease;
  pointer-events: none;
}

.sr-grid__item:hover .sr-grid__hover-overlay {
  opacity: 1;
  transform: translateY(0);
  pointer-events: auto;
}

/* ── Info-Leiste ─────────────────────────────────────────────────────────── */
.sr-grid__info {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 4px 6px;
  background: #16162a;
  min-height: 26px;
}

.sr-grid__info-stars {
  flex-shrink: 0;
}

.sr-grid__info-name {
  flex: 1;
  font-size: 11px;
  color: #aaa;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  text-align: center;
}

.sr-grid__info-color {
  flex-shrink: 0;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  display: inline-block;
}

.sr-grid__info-color--red    { background: #e05252; }
.sr-grid__info-color--yellow { background: #e0c252; }
.sr-grid__info-color--green  { background: #52a852; }
.sr-grid__info-color--blue   { background: #5277e0; }
.sr-grid__info-color--purple { background: #9b52e0; }

/* ── Skeleton ─────────────────────────────────────────────────────────────── */
.sr-grid__item--skeleton {
  pointer-events: none;
  border: none;
}

.sr-grid__skeleton-img {
  width: 100%;
  padding-top: 75%;
  background: linear-gradient(135deg, #1e1e2e 25%, #2a2a3e 50%, #1e1e2e 75%);
  background-size: 400% 400%;
  animation: shimmer 1.5s infinite;
}

.sr-grid__skeleton-bar {
  height: 26px;
  background: #16162a;
  margin-top: 2px;
  border-radius: 0 0 4px 4px;
}

/* ── Leer-Zustand ─────────────────────────────────────────────────────────── */
.sr-grid__empty-cta {
  margin-top: 4px;
  padding: 6px 16px;
  border-radius: 20px;
  border: 1px dashed #7a3050;
  background: transparent;
  color: #c06070;
  font-size: 12px;
  font-family: inherit;
  cursor: pointer;
  transition: background 150ms, color 150ms, border-color 150ms;
}
.sr-grid__empty-cta:hover {
  background: #3a2030;
  border-color: #9a4060;
  color: #e0a0b0;
}

.sr-grid__empty {
  grid-column: 1 / -1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px 20px;
  color: #555;
  gap: 12px;
}

.sr-grid__empty svg {
  width: 64px;
  height: 64px;
}

.sr-grid__empty p {
  margin: 0;
  font-size: 14px;
}

.sr-grid__empty-sub {
  font-size: 12px !important;
  color: #444;
}
</style>
