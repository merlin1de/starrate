<template>
  <div class="sr-app" :class="{ 'sr-app--loupe': mode === 'loupe' }">

    <!-- Nav-Zeile: Breadcrumb + Unterordner (auf Mobile eine scrollbare Zeile) -->
    <div class="sr-nav-row">
      <div class="sr-breadcrumb">
        <button class="sr-breadcrumb__seg" @click="navigateTo('/')">⌂</button>
        <template v-for="(seg, i) in pathSegments" :key="i">
          <span class="sr-breadcrumb__sep">/</span>
          <button class="sr-breadcrumb__seg" @click="navigateTo(pathUpTo(i))">{{ seg }}</button>
        </template>
        <button v-if="!guestMode" class="sr-breadcrumb__share" @click="showShareList = true" title="Freigabe-Links verwalten">
          Teilen
        </button>
        <span v-if="guestMode && guestLabel" class="sr-breadcrumb__guest-label">{{ guestLabel }}</span>
        <span class="sr-breadcrumb__version">
          StarRate v{{ appVersion }}<br>
          by <a href="https://www.instagram.com/merlin1.de/" target="_blank" rel="noopener noreferrer" class="sr-breadcrumb__version-link">Merlin1.De</a>
        </span>
      </div>

      <!-- Unterordner (in Loupe ausgeblendet) -->
      <div v-if="subFolders.length && mode !== 'loupe'" class="sr-folders">
      <button
        v-for="f in subFolders"
        :key="f.path"
        class="sr-folders__item"
        @click="navigateTo(f.path)"
      >
        <span class="sr-folders__icon">📁</span>
        <span class="sr-folders__name">{{ f.name }}</span>
      </button>
    </div>
    </div><!-- /.sr-nav-row -->

    <!-- Filterleiste -->
    <FilterBar
      v-model:filter="activeFilter"
      :total="allImages.length"
      :filtered-count="filteredImages.length"
      :mode="mode"
      @toggle-mode="toggleMode"
    />

    <!-- Ansichts-Wrapper: nimmt den restlichen Platz, gibt dem Grid eine definite Höhe -->
    <div class="sr-view-wrap">
      <GridView
        v-if="mode === 'grid'"
        ref="gridRef"
        :images="filteredImages"
        :loading="loading"
        :has-active-filter="hasActiveFilter"
        :current-index="currentIndex"
        :thumbnail-size="settings.thumbnail_size"
        :grid-columns="settings.grid_columns"
        :show-filename="settings.show_filename"
        :show-rating-info="settings.show_rating_overlay"
        :show-color-info="settings.show_color_overlay"
        :thumbnail-url-fn="thumbnailUrlFn"
        @rate="onRate"
        @open-loupe="openLoupe"
        @selection-change="onSelectionChange"
        @clear-filter="resetFilter"
      />

      <!-- Lupenansicht -->
      <LoupeView
        v-else
        :images="filteredImages"
        :initial-index="currentIndex"
        :on-refresh-rating="guestMode ? null : refreshImageRating"
        :preview-url-fn="previewUrlFn"
        @rate="onRate"
        @close="mode = 'grid'"
        @index-change="currentIndex = $event"
      />
    </div>

    <!-- Stapel-Bewertungsleiste -->
    <SelectionBar
      v-if="selectedIds.size > 0"
      :count="selectedIds.size"
      @rate="onBatchRate"
      @clear="gridRef?.clearSelection()"
    />

    <!-- Share-Liste -->
    <ShareList
      v-if="!guestMode && showShareList"
      ref="shareListRef"
      :nc-path="currentPath"
      @close="showShareList = false"
      @create="showShareModal = true"
    />

    <!-- Share erstellen -->
    <ShareModal
      v-if="!guestMode && showShareModal"
      :nc-path="currentPath"
      @close="showShareModal = false"
      @created="onShareCreated"
    />

    <!-- Toast-Nachrichten -->
    <Teleport to="body">
      <div class="sr-toasts">
        <TransitionGroup name="toast">
          <div
            v-for="toast in toasts"
            :key="toast.id"
            class="sr-toast"
            :class="`sr-toast--${toast.type}`"
          >
            {{ toast.message }}
          </div>
        </TransitionGroup>
      </div>
    </Teleport>

  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'

/* global __APP_VERSION__ */
const appVersion = __APP_VERSION__
import { useRoute, useRouter } from 'vue-router'
import { t, n } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import GridView from '../components/GridView.vue'
import FilterBar from '../components/FilterBar.vue'
import SelectionBar from '../components/SelectionBar.vue'
import LoupeView from '../components/LoupeView.vue'
import ShareModal from '../components/ShareModal.vue'
import ShareList from '../components/ShareList.vue'

// ─── Gast-Modus-Props (alle optional, Defaults = normales Verhalten) ───────────

const props = defineProps({
  /** Gast-Modus: kein Settings-Abruf, kein Share-UI, kein localStorage-Filter */
  guestMode:      { type: Boolean,  default: false },
  /** Name-Badge im Breadcrumb (nur im Gast-Modus) */
  guestLabel:     { type: String,   default: '' },
  /** Ersetzt /api/images — fn(path) → { images, folders } */
  loadImagesFn:   { type: Function, default: null },
  /** Ersetzt /api/rating/{id} POST — fn(fileId, payload) */
  rateFn:         { type: Function, default: null },
  /** Ersetzt /api/rating/batch POST — fn(ids, payload) */
  batchRateFn:    { type: Function, default: null },
  /** Weitergereicht an GridView */
  thumbnailUrlFn: { type: Function, default: null },
  /** Weitergereicht an LoupeView */
  previewUrlFn:   { type: Function, default: null },
})

// ─── Zustand ──────────────────────────────────────────────────────────────────

const route  = useRoute()
const router = useRouter()

const mode         = ref('grid')       // 'grid' | 'loupe'
const loading      = ref(false)
const allImages    = ref([])

const settings = ref({
  default_sort:        'name',
  default_sort_order:  'asc',
  thumbnail_size:       280,
  show_filename:        true,
  show_rating_overlay:  true,
  show_color_overlay:   true,
  grid_columns:        'auto',
})
const subFolders   = ref([])
const currentIndex = ref(0)
const selectedIds  = ref(new Set())
const gridRef      = ref(null)
const shareListRef = ref(null)
const toasts       = ref([])
let   toastCounter = 0

const showShareList  = ref(false)
const showShareModal = ref(false)

const activeFilter = ref({
  minRating: 0,     // 0 = alle  (>=)
  exactRating: null,// null = kein Exact-Filter (=)
  maxRating: null,  // null = kein Max-Filter    (<=)
  color: null,      // null = alle
  pick: null,       // null | 'pick' | 'reject'
})

// ─── Aktueller Ordnerpfad ─────────────────────────────────────────────────────

const currentPath = computed(() => {
  const p = route.params.path
  return p ? `/${Array.isArray(p) ? p.join('/') : p}` : '/'
})

const pathSegments = computed(() =>
  currentPath.value.split('/').filter(Boolean)
)

function pathUpTo(index) {
  return '/' + pathSegments.value.slice(0, index + 1).join('/')
}

function navigateTo(path) {
  if (path === '/') {
    router.push('/')
  } else {
    router.push(`/folder${path}`)
  }
}

// ─── Bilder filtern ───────────────────────────────────────────────────────────

const filteredImages = computed(() => {
  let imgs = allImages.value

  const f = activeFilter.value

  if (f.exactRating !== null) {
    imgs = imgs.filter(i => i.rating === f.exactRating)
  } else if (f.minRating > 0) {
    imgs = imgs.filter(i => i.rating >= f.minRating)
  } else if (f.maxRating !== null) {
    imgs = imgs.filter(i => i.rating <= f.maxRating)
  }

  if (f.color) {
    imgs = imgs.filter(i => i.color === f.color)
  }

  if (f.pick) {
    imgs = imgs.filter(i => i.pick === f.pick)
  }

  return imgs
})

const hasActiveFilter = computed(() =>
  activeFilter.value.minRating > 0 ||
  activeFilter.value.exactRating !== null ||
  activeFilter.value.maxRating !== null ||
  activeFilter.value.color !== null ||
  activeFilter.value.pick !== null
)

// ─── Bilder laden ─────────────────────────────────────────────────────────────

let loadSeq = 0  // Sequenzzähler: verhindert dass alte Requests neuere überschreiben

async function loadImages() {
  const seq = ++loadSeq
  loading.value = true
  try {
    let data
    if (props.loadImagesFn) {
      data = await props.loadImagesFn(currentPath.value)
    } else {
      const url = generateUrl('/apps/starrate/api/images')
      const res = await axios.get(url, {
        params: { path: currentPath.value, sort: settings.value.default_sort, order: settings.value.default_sort_order },
        timeout: 15000,
      })
      data = res.data
    }
    if (seq !== loadSeq) return  // veralteter Request – ignorieren
    allImages.value = (data.images || []).map(img => ({
      ...img,
      thumbLoaded: false,
      thumbUrl: null,
    }))
    subFolders.value = data.folders || []
  } catch (e) {
    if (seq !== loadSeq) return
    showToast(t('starrate', 'Bilder konnten nicht geladen werden'), 'error')
  } finally {
    if (seq === loadSeq) loading.value = false
  }
}

// Pfadwechsel → Bilder neu laden (kein immediate: erster Load passiert in onMounted nach Settings)
watch(currentPath, loadImages)

// ─── Bewertung setzen ─────────────────────────────────────────────────────────

async function onRate(image, rating, color, pick) {
  const payload = {}
  if (rating !== undefined) payload.rating = rating
  if (color  !== undefined) payload.color  = color
  if (pick   !== undefined) payload.pick   = pick

  if (Object.keys(payload).length === 0) return

  // Optimistisch updaten
  const local = allImages.value.find(i => i.id === image.id)
  if (local) Object.assign(local, payload)

  try {
    if (props.rateFn) {
      await props.rateFn(image.id, payload)
    } else {
      const url = generateUrl(`/apps/starrate/api/rating/${image.id}`)
      await axios.post(url, payload)
    }

    if (payload.rating !== undefined) {
      const stars = '★'.repeat(payload.rating) + '☆'.repeat(5 - payload.rating)
      showToast(t('starrate', '{name}: {stars}', { name: image.name, stars }), 'success')
    } else if (payload.color !== undefined) {
      const label = payload.color || '○'
      showToast(t('starrate', '{name}: {label}', { name: image.name, label }), 'success')
    }
  } catch (e) {
    // Rollback
    if (local) await loadImages()
    showToast(t('starrate', 'Bewertung konnte nicht gespeichert werden'), 'error')
  }
}

// ─── Stapel-Bewertung ─────────────────────────────────────────────────────────

async function onBatchRate(rating, color) {
  const ids = Array.from(selectedIds.value)
  if (ids.length === 0) return

  const payload = { fileIds: ids }
  if (rating !== null && rating !== undefined) payload.rating = rating
  if (color  !== null && color  !== undefined) payload.color  = color

  // Optimistisch
  ids.forEach(id => {
    const local = allImages.value.find(i => i.id === id)
    if (local) {
      if (payload.rating !== undefined) local.rating = payload.rating
      if (payload.color  !== undefined) local.color  = payload.color
    }
  })

  try {
    if (props.batchRateFn) {
      await props.batchRateFn(ids, { rating: payload.rating, color: payload.color })
    } else {
      const url = generateUrl('/apps/starrate/api/rating/batch')
      const { data } = await axios.post(url, payload)
      if (data.errors > 0) {
        showToast(n('starrate', '%n Fehler', '%n Fehler', data.errors), 'error')
      }
    }

    const stars = payload.rating !== undefined
      ? '★'.repeat(payload.rating) + '☆'.repeat(5 - payload.rating)
      : ''
    showToast(
      n('starrate', '%n Bild bewertet %s', '%n Bilder bewertet %s', ids.length, stars),
      'success'
    )
  } catch (e) {
    await loadImages()
    showToast(t('starrate', 'Stapel-Bewertung fehlgeschlagen'), 'error')
  }
}

// ─── Filter ↔ URL-Query-Params ────────────────────────────────────────────────

function filterToQuery(f) {
  const q = {}
  if (f.minRating   > 0)      q.r  = String(f.minRating)
  if (f.exactRating !== null)  q.re = String(f.exactRating)
  if (f.maxRating   !== null)  q.rm = String(f.maxRating)
  if (f.color)                 q.c  = f.color
  if (f.pick)                  q.p  = f.pick
  return q
}

function queryToFilter(q) {
  return {
    minRating:   q.r  !== undefined ? Number(q.r)  : 0,
    exactRating: q.re !== undefined ? Number(q.re) : null,
    maxRating:   q.rm !== undefined ? Number(q.rm) : null,
    color:       q.c  || null,
    pick:        q.p  || null,
  }
}

// ─── Filter zurücksetzen ──────────────────────────────────────────────────────

function resetFilter() {
  activeFilter.value = { minRating: 0, exactRating: null, maxRating: null, color: null, pick: null }
}

// ─── Modus-Wechsel ────────────────────────────────────────────────────────────

function toggleMode() {
  mode.value = mode.value === 'grid' ? 'loupe' : 'grid'
}

function openLoupe(image, index) {
  currentIndex.value = index
  selectedIds.value = new Set()
  mode.value = 'loupe'
}

// ─── Auswahl ──────────────────────────────────────────────────────────────────

function onSelectionChange(ids) {
  selectedIds.value = ids
}

// ─── Toast ────────────────────────────────────────────────────────────────────

function showToast(message, type = 'success') {
  const id = ++toastCounter
  toasts.value.push({ id, message, type })
  setTimeout(() => {
    toasts.value = toasts.value.filter(t => t.id !== id)
  }, 3000)
}

// ─── Share ────────────────────────────────────────────────────────────────────

function onShareCreated(share) {
  showShareModal.value = false
  // Liste neu laden damit der neue Share erscheint
  shareListRef.value?.loadShares()
}

// Escape auf Dokument-Ebene: klärt Auswahl unabhängig davon, welches Element Fokus hat
function onDocKeydown(e) {
  if (e.key === 'Escape' && selectedIds.value.size > 0) {
    gridRef.value?.clearSelection()
  }
}

async function loadSettings() {
  if (props.guestMode) return  // Gast nutzt Standardwerte
  try {
    const url = generateUrl('/apps/starrate/api/settings')
    const { data } = await axios.get(url)
    Object.assign(settings.value, data)
  } catch {
    // Standardwerte behalten
  }
}

// ─── Sync-Light: einzelnes Bild aktualisieren (für Loupe-Navigation) ──────────

async function refreshImageRating(image) {
  if (!image?.id) return
  try {
    const url = generateUrl(`/apps/starrate/api/rating/${image.id}`)
    const { data } = await axios.get(url, { timeout: 5000 })
    const local = allImages.value.find(i => i.id === image.id)
    if (local) Object.assign(local, { rating: data.rating, color: data.color, pick: data.pick })
  } catch {
    // still ignore – user sees last known value
  }
}

// ─── Background-Sync: alle 60s stiller Reload wenn Tab sichtbar ───────────────

const SYNC_INTERVAL_MS = 5 * 60_000  // 5 Minuten
let syncTimer = null

function startBackgroundSync() {
  clearInterval(syncTimer)
  syncTimer = setInterval(() => {
    if (!document.hidden && !loading.value) loadImages()
  }, SYNC_INTERVAL_MS)
}

function stopBackgroundSync() {
  clearInterval(syncTimer)
  syncTimer = null
}

// ─── Visibility-Refresh: Tab kommt in Vordergrund → Ordner neu laden ──────────

function onVisibilityChange() {
  if (!document.hidden && !loading.value) loadImages()
}

onMounted(async () => {
  document.addEventListener('keydown', onDocKeydown)
  document.addEventListener('visibilitychange', onVisibilityChange)
  startBackgroundSync()

  // Settings laden, dann erst Bilder (damit sort/order korrekt ist)
  await loadSettings()

  // URL-Query-Params / localStorage nur im normalen Modus
  if (!props.guestMode) {
    const q = route.query
    const hasUrlFilter = ['r', 're', 'rm', 'c', 'p'].some(k => k in q)
    if (hasUrlFilter) {
      Object.assign(activeFilter.value, queryToFilter(q))
    } else {
      const saved = localStorage.getItem(`starrate_filter_${currentPath.value}`)
      if (saved) {
        try { Object.assign(activeFilter.value, JSON.parse(saved)) } catch {}
      }
    }
  }

  // Erster Bildladevorgang nach Settings & Filter
  loadImages()
})

onUnmounted(() => {
  document.removeEventListener('keydown', onDocKeydown)
  document.removeEventListener('visibilitychange', onVisibilityChange)
  stopBackgroundSync()
})

// Filter in localStorage + URL persistieren (nur normaler Modus)
watch(activeFilter, val => {
  if (props.guestMode) return
  localStorage.setItem(`starrate_filter_${currentPath.value}`, JSON.stringify(val))
  router.replace({ query: filterToQuery(val) })
}, { deep: true })

// Browser-Back/Forward: URL-Änderung → Filter aktualisieren
watch(() => route.query, q => {
  Object.assign(activeFilter.value, queryToFilter(q))
}, { deep: true })
</script>

<style scoped>
.sr-app {
  display: flex;
  flex-direction: column;
  width: 100%;
  flex: 1;        /* height:100% ist in Flex-Kontext unzuverlässig → flex:1 */
  min-height: 0;  /* erlaubt Flex-Kind zu schrumpfen und intern zu scrollen */
  background: #1a1a2e;
  color: #e0e0e0;
  font-family: 'Inter', system-ui, -apple-system, sans-serif;
  overflow: hidden;
}

.sr-app--loupe {
  background: #000;
}

/* Toasts */
.sr-toasts {
  position: fixed;
  top: 60px;
  right: 16px;
  bottom: auto;
  left: auto;
  transform: none;
  display: flex;
  flex-direction: column;
  gap: 8px;
  z-index: 9999;
  pointer-events: none;
}

.sr-toast {
  padding: 10px 20px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 500;
  white-space: nowrap;
  box-shadow: 0 4px 12px rgba(0,0,0,0.4);
  pointer-events: auto;
}

.sr-toast--success { background: #2a4a2a; color: #7ecf7e; border: 1px solid #3a6a3a; }
.sr-toast--error   { background: #4a1a1a; color: #e94560; border: 1px solid #6a2a2a; }
.sr-toast--info    { background: #1a2a4a; color: #7eaecf; border: 1px solid #2a3a6a; }

.toast-enter-active,
.toast-leave-active {
  transition: all 250ms ease;
}
.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(12px);
}

/* Nav-Zeile: Breadcrumb + SubFolders */
.sr-nav-row {
  display: contents; /* Desktop: transparent, Kinder nehmen am sr-app-Flex teil */
}

/* Breadcrumb: scoped für höhere Spezifizität gegenüber NC-Styles */
.sr-breadcrumb {
  display: flex;
  align-items: center;
  width: 100%;
}

.sr-view-wrap {
  flex: 1;
  min-height: 0;
}

.sr-breadcrumb__share {
  background: #2a2a3e;
  border: 1px solid #3f3f5a;
  border-radius: 4px;
  color: #a1a1aa;
  cursor: pointer;
  font-size: 11px;
  padding: 2px 8px;
  white-space: nowrap;
  flex-shrink: 0;
  transition: color 0.15s, border-color 0.15s;
}
.sr-breadcrumb__share:hover {
  color: #d4d4d8;
  border-color: #7a3050;
}

.sr-breadcrumb__guest-label {
  background: #2a2a3e;
  border: 1px solid #3f3f5a;
  border-radius: 4px;
  color: #a1a1aa;
  font-size: 11px;
  padding: 2px 8px;
  white-space: nowrap;
  flex-shrink: 0;
}

.sr-breadcrumb__version {
  margin-left: auto;
  font-size: 10px;
  color: #7a7a96;
  user-select: none;
  letter-spacing: 0.04em;
  padding-left: 24px;
  white-space: nowrap;
  flex-shrink: 0;
  text-align: right;
  line-height: 1.5;
}

.sr-breadcrumb__version-link,
.sr-breadcrumb__version-link:visited,
.sr-breadcrumb__version-link:hover,
.sr-breadcrumb__version-link:active {
  color: #8a8aa8 !important;
  text-decoration: none;
}

.sr-breadcrumb__version-link:hover {
  text-decoration: underline;
}

/* ── Mobile: Nav-Zeile als einzelne scrollbare Reihe ─────────────────────── */
@media (pointer: coarse) {
  .sr-nav-row {
    display: flex;
    flex-direction: row;
    align-items: center;
    overflow-x: auto;
    scrollbar-width: none;
    flex-shrink: 0;
    gap: 0;
  }
  .sr-nav-row::-webkit-scrollbar { display: none; }

  .sr-nav-row .sr-breadcrumb {
    flex-shrink: 0;
    width: auto;
    padding: 4px 8px;
  }
  .sr-nav-row .sr-folders {
    flex-shrink: 0;
    padding: 4px 8px 4px 0;
    border: none;
    background: transparent;
  }
  .sr-breadcrumb__version { display: none; }
}
</style>
