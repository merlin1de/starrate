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
        <button v-if="!guestMode" class="sr-breadcrumb__share" @click="showShareList = true" :title="t('starrate', 'Freigabe-Links verwalten')">
          {{ t('starrate', 'Teilen') }}
        </button>
        <span v-if="guestMode && guestLabel" class="sr-breadcrumb__guest-label">{{ guestLabel }}</span>
        <!-- Modus-Toggle: nur Desktop (Mobile: in FilterBar) -->
        <div class="sr-breadcrumb__mode">
          <button
            class="sr-breadcrumb__mode-btn"
            :class="{ 'sr-breadcrumb__mode-btn--active': mode === 'grid' }"
            type="button"
            :title="t('starrate', 'Rasteransicht')"
            @click="toggleMode"
          >
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <rect x="3" y="3" width="7" height="7" rx="1" fill="currentColor"/>
              <rect x="14" y="3" width="7" height="7" rx="1" fill="currentColor"/>
              <rect x="3" y="14" width="7" height="7" rx="1" fill="currentColor"/>
              <rect x="14" y="14" width="7" height="7" rx="1" fill="currentColor"/>
            </svg>
          </button>
          <button
            class="sr-breadcrumb__mode-btn"
            :class="{ 'sr-breadcrumb__mode-btn--active': mode === 'loupe' }"
            type="button"
            :title="t('starrate', 'Lupenansicht')"
            @click="toggleMode"
          >
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <rect x="2" y="2" width="20" height="20" rx="2" stroke="currentColor" stroke-width="2"/>
              <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/>
            </svg>
          </button>
        </div>

        <!-- Shortcut-Hilfe -->
        <button class="sr-breadcrumb__help" :title="t('starrate', 'Tastaturkürzel')" @click="showShortcuts = true">?</button>

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
      :enable-pick-ui="settings.enable_pick_ui"
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
        :enable-pick-ui="settings.enable_pick_ui"
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
        :enable-pick-ui="settings.enable_pick_ui"
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

    <!-- Shortcut-Hilfe-Modal -->
    <Teleport to="body">
      <Transition name="sr-shortcuts">
        <div v-if="showShortcuts" class="sr-shortcuts-overlay" @click.self="showShortcuts = false">
          <div class="sr-shortcuts-dialog">
            <div class="sr-shortcuts-header">
              <span>{{ t('starrate', 'Tastaturkürzel') }}</span>
              <button class="sr-shortcuts-close" @click="showShortcuts = false">✕</button>
            </div>
            <div class="sr-shortcuts-body">
              <div class="sr-shortcuts-group">
                <div class="sr-shortcuts-group-title">{{ t('starrate', 'Navigation') }}</div>
                <div class="sr-shortcuts-row"><kbd>← → ↑ ↓</kbd><span>{{ t('starrate', 'Bild wechseln') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>Shift + Pfeile</kbd><span>{{ t('starrate', 'Mehrfachauswahl') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>Strg + A</kbd><span>{{ t('starrate', 'Alle auswählen') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>Esc</kbd><span>{{ t('starrate', 'Auswahl aufheben') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>Enter</kbd><span>{{ t('starrate', 'Lupenansicht öffnen') }}</span></div>
              </div>
              <div class="sr-shortcuts-group">
                <div class="sr-shortcuts-group-title">{{ t('starrate', 'Bewertung') }}</div>
                <div class="sr-shortcuts-row"><kbd>0 – 5</kbd><span>{{ t('starrate', 'Sterne setzen') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>6</kbd><span>{{ t('starrate', 'Rot') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>7</kbd><span>{{ t('starrate', 'Gelb') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>8</kbd><span>{{ t('starrate', 'Grün') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>9</kbd><span>{{ t('starrate', 'Blau') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>V</kbd><span>{{ t('starrate', 'Lila') }}</span></div>
              </div>
              <div v-if="settings.enable_pick_ui" class="sr-shortcuts-group">
                <div class="sr-shortcuts-group-title">{{ t('starrate', 'Auswahl') }}</div>
                <div class="sr-shortcuts-row"><kbd>P</kbd><span>Pick</span></div>
                <div class="sr-shortcuts-row"><kbd>X</kbd><span>Reject</span></div>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

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
  enable_pick_ui:       false,
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
const showShortcuts  = ref(false)

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
  if (mode.value === 'loupe') mode.value = 'grid'
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

  if (f.pick && settings.value.enable_pick_ui) {
    imgs = imgs.filter(i => i.pick === f.pick)
  }

  return imgs
})

const hasActiveFilter = computed(() =>
  activeFilter.value.minRating > 0 ||
  activeFilter.value.exactRating !== null ||
  activeFilter.value.maxRating !== null ||
  activeFilter.value.color !== null ||
  (settings.value.enable_pick_ui && activeFilter.value.pick !== null)
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
      thumbLoading: false,
      thumbUrl: null,
      thumbRetries: 0,
      thumbError: false,
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

// Pick-Filter zurücksetzen wenn Pick-UI deaktiviert wird
watch(() => settings.value.enable_pick_ui, enabled => {
  if (!enabled && activeFilter.value.pick !== null) {
    activeFilter.value = { ...activeFilter.value, pick: null }
  }
})

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
    } else if (payload.pick !== undefined) {
      const label = payload.pick === 'pick' ? '✓ Pick' : payload.pick === 'reject' ? '⊘ Reject' : '— ' + t('starrate', 'kein Pick')
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

    const bildText = n('starrate', '%n Bild', '%n Bilder', ids.length)
    const stars = payload.rating !== undefined
      ? ' — ' + '★'.repeat(payload.rating) + (payload.rating < 5 ? '☆'.repeat(5 - payload.rating) : '')
      : ''
    showToast(`${bildText} bewertet${stars}`, 'success')
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

// Android Back-Button: Loupe → Grid statt Browser-Navigation
// Beim Öffnen der Loupe einen History-Eintrag pushen; beim Schließen (ESC/X)
// den Eintrag konsumieren; popstate (Android Back) setzt mode auf grid.
function onPopState() {
  if (mode.value === 'loupe') {
    mode.value = 'grid'
  }
}

watch(mode, (newVal, oldVal) => {
  if (newVal === 'loupe') {
    history.pushState({ srLoupe: true }, '')
  } else if (oldVal === 'loupe') {
    // Loupe per ESC/X geschlossen (nicht per popstate) → History-Eintrag konsumieren
    if (history.state?.srLoupe) {
      history.back()
    }
  }
})

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

// Escape auf Dokument-Ebene: schließt Modals von innen nach außen, dann Auswahl
function onDocKeydown(e) {
  if (e.key !== 'Escape') return
  if (showShareModal.value)       { showShareModal.value = false; return }
  if (showShareList.value)        { showShareList.value  = false; return }
  if (showShortcuts.value)        { showShortcuts.value  = false; return }
  if (selectedIds.value.size > 0) { gridRef.value?.clearSelection() }
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
  window.addEventListener('popstate', onPopState)
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
  window.removeEventListener('popstate', onPopState)
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

.sr-folders {
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  gap: 4px;
  padding: 4px 8px;
  width: 100%;
}

.sr-folders__item {
  display: flex;
  align-items: center;
  gap: 4px;
  background: #1a1a2e;
  border: 1px solid #2a2a4a;
  border-radius: 4px;
  color: #a1a1aa;
  cursor: pointer;
  font-size: 12px;
  padding: 2px 8px;
  white-space: nowrap;
  transition: color 0.15s, border-color 0.15s;
}
@media (pointer: fine) {
  .sr-folders__item:hover {
    color: #d4d4d8;
    border-color: #5a5a8a;
  }
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

.sr-breadcrumb__mode {
  display: flex;
  background: #1a1a2e;
  border: 1px solid #2a2a4a;
  border-radius: 6px;
  overflow: hidden;
  margin-left: auto;
  flex-shrink: 0;
}

.sr-breadcrumb__mode-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 28px;
  padding: 0 !important;
  border: none !important;
  background: transparent;
  color: #666;
  cursor: pointer;
  transition: background 150ms, color 150ms;
  appearance: none !important;
  -webkit-appearance: none !important;
  box-shadow: none !important;
}

.sr-breadcrumb__mode-btn:hover { color: #aaa; background: #2a2a4a; }
.sr-breadcrumb__mode-btn:focus,
.sr-breadcrumb__mode-btn:focus-visible,
.sr-breadcrumb__mode-btn:active { box-shadow: none !important; outline: none !important; }
.sr-breadcrumb__mode-btn--active { background: #3a1a28 !important; color: #d08090 !important; }
.sr-breadcrumb__mode-btn svg { width: 15px; height: 15px; }

.sr-breadcrumb__version {
  margin-left: 8px;
  font-size: 10px;
  color: #7a7a96;
  user-select: none;
  letter-spacing: 0.04em;
  padding-left: 12px;
  white-space: nowrap;
  flex-shrink: 0;
  text-align: right;
  line-height: 1.5;
  opacity: 0.35;
  transition: opacity 250ms;
}
.sr-breadcrumb__version:hover { opacity: 1; }

.sr-breadcrumb__version-link,
.sr-breadcrumb__version-link:visited,
.sr-breadcrumb__version-link:hover,
.sr-breadcrumb__version-link:active {
  color: #8a8aa8 !important;
  text-decoration: underline !important;
}

/* ── Help-Button ─────────────────────────────────────────────────────────── */
.sr-breadcrumb__help {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 1px solid #2a2a4a;
  background: transparent;
  color: #555;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  flex-shrink: 0;
  margin-left: 6px;
  transition: color 150ms, border-color 150ms;
  box-shadow: none !important;
  line-height: 1;
  padding: 0 !important;
}
.sr-breadcrumb__help:hover { color: #aaa; border-color: #555; }
.sr-breadcrumb__help:focus,
.sr-breadcrumb__help:active { outline: none !important; box-shadow: none !important; }

/* ── Shortcut-Modal ──────────────────────────────────────────────────────── */
.sr-shortcuts-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9000;
}

.sr-shortcuts-dialog {
  background: #16213e;
  border: 1px solid #2a2a4a;
  border-radius: 12px;
  width: min(480px, 92vw);
  overflow: hidden;
}

.sr-shortcuts-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  border-bottom: 1px solid #2a2a4a;
  font-size: 13px;
  font-weight: 600;
  color: #d4d4e8;
}

.sr-shortcuts-close {
  background: transparent;
  border: none;
  color: #666;
  cursor: pointer;
  font-size: 14px;
  padding: 0;
  line-height: 1;
  transition: color 150ms;
}
.sr-shortcuts-close:hover { color: #aaa; }

.sr-shortcuts-body {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0;
  padding: 0;
}

.sr-shortcuts-group {
  padding: 14px 16px;
  border-right: 1px solid #2a2a4a;
}
.sr-shortcuts-group:last-child { border-right: none; }

.sr-shortcuts-group-title {
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #555;
  margin-bottom: 10px;
  font-weight: 600;
}

.sr-shortcuts-row {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin-bottom: 6px;
}

.sr-shortcuts-row kbd {
  font-size: 10px;
  background: #1a1a2e;
  border: 1px solid #3a3a5a;
  border-radius: 4px;
  padding: 1px 5px;
  color: #a1a1cc;
  white-space: nowrap;
  flex-shrink: 0;
  font-family: inherit;
}

.sr-shortcuts-row span {
  font-size: 11px;
  color: #888;
}

.sr-shortcuts-enter-active,
.sr-shortcuts-leave-active { transition: opacity 150ms; }
.sr-shortcuts-enter-from,
.sr-shortcuts-leave-to { opacity: 0; }

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
  .sr-breadcrumb__mode    { display: none; }
}
</style>
