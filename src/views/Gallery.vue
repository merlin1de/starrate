<template>
  <div class="sr-app" :class="{ 'sr-app--loupe': mode === 'loupe' }">

    <!-- Breadcrumb-Navigation -->
    <div class="sr-breadcrumb">
      <button class="sr-breadcrumb__seg" @click="navigateTo('/')">⌂</button>
      <template v-for="(seg, i) in pathSegments" :key="i">
        <span class="sr-breadcrumb__sep">/</span>
        <button class="sr-breadcrumb__seg" @click="navigateTo(pathUpTo(i))">{{ seg }}</button>
      </template>
    </div>

    <!-- Filterleiste -->
    <FilterBar
      v-model:filter="activeFilter"
      :total="allImages.length"
      :filtered-count="filteredImages.length"
      :mode="mode"
      @toggle-mode="toggleMode"
    />

    <!-- Unterordner -->
    <div v-if="subFolders.length" class="sr-folders">
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

    <!-- Rasteransicht -->
    <GridView
      v-if="mode === 'grid'"
      ref="gridRef"
      :images="filteredImages"
      :loading="loading"
      :has-active-filter="hasActiveFilter"
      :current-index="currentIndex"
      @rate="onRate"
      @open-loupe="openLoupe"
      @selection-change="onSelectionChange"
    />

    <!-- Lupenansicht -->
    <LoupeView
      v-else
      :images="filteredImages"
      :initial-index="currentIndex"
      @rate="onRate"
      @close="mode = 'grid'"
      @index-change="currentIndex = $event"
    />

    <!-- Stapel-Bewertungsleiste -->
    <SelectionBar
      v-if="selectedIds.size > 0"
      :count="selectedIds.size"
      @rate="onBatchRate"
      @clear="gridRef?.clearSelection()"
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
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { t, n } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import GridView from '../components/GridView.vue'
import FilterBar from '../components/FilterBar.vue'
import SelectionBar from '../components/SelectionBar.vue'

// Lazy imports für LoupeView (Code-Splitting)
import { defineAsyncComponent } from 'vue'
const LoupeView = defineAsyncComponent(() => import('../components/LoupeView.vue'))

// ─── Zustand ──────────────────────────────────────────────────────────────────

const route  = useRoute()
const router = useRouter()

const mode         = ref('grid')       // 'grid' | 'loupe'
const loading      = ref(false)
const allImages    = ref([])
const subFolders   = ref([])
const currentIndex = ref(0)
const selectedIds  = ref(new Set())
const gridRef      = ref(null)
const toasts       = ref([])
let   toastCounter = 0

const activeFilter = ref({
  minRating: 0,     // 0 = alle
  exactRating: null,// null = kein Exact-Filter
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
  activeFilter.value.color !== null ||
  activeFilter.value.pick !== null
)

// ─── Bilder laden ─────────────────────────────────────────────────────────────

async function loadImages() {
  loading.value = true
  try {
    const url = generateUrl('/apps/starrate/api/images')
    const { data } = await axios.get(url, {
      params: { path: currentPath.value, sort: 'name', order: 'asc' },
    })
    allImages.value = (data.images || []).map(img => ({
      ...img,
      thumbLoaded: false,
      thumbUrl: null,
    }))
    subFolders.value = data.folders || []
  } catch (e) {
    showToast(t('starrate', 'Bilder konnten nicht geladen werden'), 'error')
  } finally {
    loading.value = false
  }
}

watch(currentPath, loadImages, { immediate: true })

// ─── Bewertung setzen ─────────────────────────────────────────────────────────

async function onRate(image, rating, color, pick) {
  const payload = {}
  if (rating !== null && rating !== undefined) payload.rating = rating
  if (color  !== null && color  !== undefined) payload.color  = color
  if (pick   !== null && pick   !== undefined) payload.pick   = pick

  if (Object.keys(payload).length === 0) return

  // Optimistisch updaten
  const local = allImages.value.find(i => i.id === image.id)
  if (local) Object.assign(local, payload)

  try {
    const url = generateUrl(`/apps/starrate/api/rating/${image.id}`)
    await axios.post(url, payload)

    // Kurzes Toast nur bei Sternebewertung
    if (payload.rating !== undefined) {
      const stars = '★'.repeat(payload.rating) + '☆'.repeat(5 - payload.rating)
      showToast(t('starrate', '{name}: {stars}', { name: image.name, stars }), 'success')
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
    const url = generateUrl('/apps/starrate/api/rating/batch')
    const { data } = await axios.post(url, payload)

    const stars = payload.rating !== undefined
      ? '★'.repeat(payload.rating) + '☆'.repeat(5 - payload.rating)
      : ''
    showToast(
      n('starrate', '%n Bild bewertet %s', '%n Bilder bewertet %s', ids.length, stars),
      'success'
    )

    if (data.errors > 0) {
      showToast(n('starrate', '%n Fehler', '%n Fehler', data.errors), 'error')
    }
  } catch (e) {
    await loadImages()
    showToast(t('starrate', 'Stapel-Bewertung fehlgeschlagen'), 'error')
  }
}

// ─── Modus-Wechsel ────────────────────────────────────────────────────────────

function toggleMode() {
  mode.value = mode.value === 'grid' ? 'loupe' : 'grid'
}

function openLoupe(image, index) {
  currentIndex.value = index
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

onMounted(() => {
  // Filter aus localStorage wiederherstellen
  const saved = localStorage.getItem(`starrate_filter_${currentPath.value}`)
  if (saved) {
    try {
      Object.assign(activeFilter.value, JSON.parse(saved))
    } catch {}
  }
})

// Filter in localStorage persistieren
watch(activeFilter, val => {
  localStorage.setItem(`starrate_filter_${currentPath.value}`, JSON.stringify(val))
}, { deep: true })
</script>

<style scoped>
.sr-app {
  display: flex;
  flex-direction: column;
  height: 100%;
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
  bottom: 24px;
  left: 50%;
  transform: translateX(-50%);
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
</style>
