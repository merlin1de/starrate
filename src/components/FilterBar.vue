<template>
  <div class="sr-filterbar">
    <!-- Linke Seite: Filter -->
    <div class="sr-filterbar__filters" role="toolbar" :aria-label="t('starrate', 'Bildfilter')">

      <!-- Sterne-Filter -->
      <div class="sr-filterbar__group" role="group" :aria-label="t('starrate', 'Sterne')">
        <button
          v-for="opt in ratingOptions"
          :key="opt.value"
          class="sr-filterbar__pill"
          :class="{ 'sr-filterbar__pill--active': isRatingActive(opt) }"
          type="button"
          :aria-pressed="isRatingActive(opt)"
          :title="opt.label"
          @click="setRatingFilter(opt)"
        >
          {{ opt.label }}
        </button>
      </div>

      <div class="sr-filterbar__sep" aria-hidden="true" />

      <!-- Farb-Filter -->
      <div class="sr-filterbar__group" role="group" :aria-label="t('starrate', 'Farben')">
        <button
          v-for="color in colorOptions"
          :key="color.key"
          class="sr-filterbar__pill sr-filterbar__pill--color"
          :class="{ 'sr-filterbar__pill--active': filter.color === color.key }"
          type="button"
          :aria-pressed="filter.color === color.key"
          :title="color.label"
          @click="toggleColorFilter(color.key)"
        >
          <span
            class="sr-filterbar__color-dot"
            :style="{ background: color.hex }"
            aria-hidden="true"
          />
          <span class="sr-filterbar__color-label">{{ color.label }}</span>
        </button>
      </div>

      <div class="sr-filterbar__sep" aria-hidden="true" />

      <!-- Pick/Reject-Filter -->
      <div class="sr-filterbar__group" role="group" :aria-label="t('starrate', 'Auswahl')">
        <button
          class="sr-filterbar__pill"
          :class="{ 'sr-filterbar__pill--active': filter.pick === 'pick' }"
          type="button"
          :title="t('starrate', 'Nur Picks anzeigen')"
          @click="togglePickFilter('pick')"
        >
          P
        </button>
        <button
          class="sr-filterbar__pill sr-filterbar__pill--reject"
          :class="{ 'sr-filterbar__pill--active': filter.pick === 'reject' }"
          type="button"
          :title="t('starrate', 'Nur Ablehnungen anzeigen')"
          @click="togglePickFilter('reject')"
        >
          X
        </button>
      </div>

      <!-- Reset: nur anzeigen wenn aktiver Filter -->
      <Transition name="fade">
        <button
          v-if="hasActiveFilter"
          class="sr-filterbar__reset"
          type="button"
          :aria-label="t('starrate', 'Alle Filter zurücksetzen')"
          @click="resetFilters"
        >
          {{ t('starrate', 'Alle anzeigen') }}
        </button>
      </Transition>

      <!-- Aktive Filter als Summary-Pills -->
      <div v-if="hasActiveFilter" class="sr-filterbar__active-summary" aria-live="polite">
        <span class="sr-filterbar__count">
          {{ n('starrate', '%n Bild', '%n Bilder', filteredCount) }}
          <span class="sr-filterbar__count-sep">/</span>
          {{ n('starrate', '%n gesamt', '%n gesamt', total) }}
        </span>
      </div>
    </div>

    <!-- Rechte Seite: Modus + Ordner-Info -->
    <div class="sr-filterbar__right">
      <!-- Modus-Umschalter -->
      <div class="sr-filterbar__mode" role="group" :aria-label="t('starrate', 'Ansicht')">
        <button
          class="sr-filterbar__mode-btn"
          :class="{ 'sr-filterbar__mode-btn--active': mode === 'grid' }"
          type="button"
          :aria-pressed="mode === 'grid'"
          :title="t('starrate', 'Rasteransicht')"
          @click="$emit('toggle-mode')"
        >
          <!-- Grid-Icon -->
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <rect x="3" y="3" width="7" height="7" rx="1" fill="currentColor"/>
            <rect x="14" y="3" width="7" height="7" rx="1" fill="currentColor"/>
            <rect x="3" y="14" width="7" height="7" rx="1" fill="currentColor"/>
            <rect x="14" y="14" width="7" height="7" rx="1" fill="currentColor"/>
          </svg>
        </button>
        <button
          class="sr-filterbar__mode-btn"
          :class="{ 'sr-filterbar__mode-btn--active': mode === 'loupe' }"
          type="button"
          :aria-pressed="mode === 'loupe'"
          :title="t('starrate', 'Lupenansicht')"
          @click="$emit('toggle-mode')"
        >
          <!-- Loupe-Icon -->
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <rect x="2" y="2" width="20" height="20" rx="2" stroke="currentColor" stroke-width="2"/>
            <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/>
          </svg>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { t, n } from '@nextcloud/l10n'

const COLOR_OPTIONS = [
  { key: 'Red',    label: t('starrate', 'Rot'),   hex: '#e05252' },
  { key: 'Yellow', label: t('starrate', 'Gelb'),  hex: '#e0c252' },
  { key: 'Green',  label: t('starrate', 'Grün'),  hex: '#52a852' },
  { key: 'Blue',   label: t('starrate', 'Blau'),  hex: '#5277e0' },
  { key: 'Purple', label: t('starrate', 'Lila'),  hex: '#9b52e0' },
]

const RATING_OPTIONS = [
  { label: '★★★★★', minRating: null, exactRating: 5 },
  { label: '★★★★',  minRating: null, exactRating: 4 },
  { label: '≥ 3★',  minRating: 3,    exactRating: null },
  { label: t('starrate', 'Unbewertet'), minRating: null, exactRating: 0 },
]

const props = defineProps({
  filter: {
    type: Object,
    required: true,
  },
  total: {
    type: Number,
    default: 0,
  },
  filteredCount: {
    type: Number,
    default: 0,
  },
  mode: {
    type: String,
    default: 'grid',
  },
})

const emit = defineEmits(['update:filter', 'toggle-mode'])

const route  = useRoute()
const router = useRouter()

const ratingOptions = RATING_OPTIONS
const colorOptions  = COLOR_OPTIONS

const hasActiveFilter = computed(() =>
  props.filter.minRating > 0 ||
  props.filter.exactRating !== null ||
  props.filter.color !== null ||
  props.filter.pick !== null
)

// ─── Filter-Aktionen ──────────────────────────────────────────────────────────

function setRatingFilter(opt) {
  const current = props.filter
  // Nochmals klicken → deaktivieren
  const alreadyActive =
    current.minRating    === (opt.minRating    ?? 0) &&
    current.exactRating  === opt.exactRating

  const newFilter = {
    ...current,
    minRating:   alreadyActive ? 0    : (opt.minRating ?? 0),
    exactRating: alreadyActive ? null : opt.exactRating,
  }
  updateFilter(newFilter)
}

function isRatingActive(opt) {
  return (
    props.filter.minRating    === (opt.minRating ?? 0) &&
    props.filter.exactRating  === opt.exactRating
  )
}

function toggleColorFilter(colorKey) {
  updateFilter({
    ...props.filter,
    color: props.filter.color === colorKey ? null : colorKey,
  })
}

function togglePickFilter(pick) {
  updateFilter({
    ...props.filter,
    pick: props.filter.pick === pick ? null : pick,
  })
}

function resetFilters() {
  updateFilter({
    minRating:   0,
    exactRating: null,
    color:       null,
    pick:        null,
  })
}

function updateFilter(newFilter) {
  emit('update:filter', newFilter)
  syncUrlParams(newFilter)
}

// ─── URL-Sync ─────────────────────────────────────────────────────────────────

function syncUrlParams(filter) {
  const query = {}
  if (filter.exactRating !== null) query.rating = String(filter.exactRating)
  else if (filter.minRating > 0)   query.min_rating = String(filter.minRating)
  if (filter.color) query.color = filter.color
  if (filter.pick)  query.pick  = filter.pick

  router.replace({ query })
}
</script>

<style scoped>
.sr-filterbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 8px 12px;
  background: #12122a;
  border-bottom: 1px solid #2a2a4a;
  flex-shrink: 0;
  flex-wrap: wrap;
  min-height: 46px;
}

.sr-filterbar__filters {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  flex: 1;
}

.sr-filterbar__group {
  display: flex;
  align-items: center;
  gap: 4px;
}

.sr-filterbar__sep {
  width: 1px;
  height: 20px;
  background: #2a2a4a;
  flex-shrink: 0;
}

/* ── Pills ────────────────────────────────────────────────────────────────── */
.sr-filterbar__pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 10px;
  border-radius: 20px;
  border: 1px solid #2a2a4a;
  background: transparent;
  color: #aaa;
  font-size: 12px;
  font-family: inherit;
  cursor: pointer;
  transition: background 150ms, color 150ms, border-color 150ms;
  white-space: nowrap;
}

.sr-filterbar__pill:hover {
  background: #2a2a4a;
  color: #ddd;
}

.sr-filterbar__pill--active {
  background: #e94560;
  border-color: #e94560;
  color: #fff;
}

.sr-filterbar__pill--active:hover {
  background: #c73550;
  border-color: #c73550;
}

.sr-filterbar__pill--reject.sr-filterbar__pill--active {
  background: #e05252;
  border-color: #e05252;
}

/* Farb-Pills */
.sr-filterbar__pill--color {
  padding: 3px 8px;
}

.sr-filterbar__color-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  flex-shrink: 0;
}

.sr-filterbar__color-label {
  display: none; /* Im kompakten Modus nur Dot zeigen */
}

@media (min-width: 900px) {
  .sr-filterbar__color-label {
    display: inline;
  }
}

/* ── Reset ────────────────────────────────────────────────────────────────── */
.sr-filterbar__reset {
  padding: 3px 10px;
  border-radius: 20px;
  border: 1px dashed #e94560;
  background: transparent;
  color: #e94560;
  font-size: 12px;
  font-family: inherit;
  cursor: pointer;
  transition: background 150ms, color 150ms;
}

.sr-filterbar__reset:hover {
  background: #e94560;
  color: #fff;
}

/* ── Anzahl ───────────────────────────────────────────────────────────────── */
.sr-filterbar__count {
  font-size: 11px;
  color: #666;
  white-space: nowrap;
}

.sr-filterbar__count-sep {
  margin: 0 3px;
}

/* ── Rechte Seite ─────────────────────────────────────────────────────────── */
.sr-filterbar__right {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.sr-filterbar__mode {
  display: flex;
  background: #1a1a2e;
  border: 1px solid #2a2a4a;
  border-radius: 6px;
  overflow: hidden;
}

.sr-filterbar__mode-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 30px;
  padding: 0;
  border: none;
  background: transparent;
  color: #666;
  cursor: pointer;
  transition: background 150ms, color 150ms;
}

.sr-filterbar__mode-btn:hover {
  color: #aaa;
  background: #2a2a4a;
}

.sr-filterbar__mode-btn--active {
  background: #e94560;
  color: #fff;
}

.sr-filterbar__mode-btn svg {
  width: 16px;
  height: 16px;
}

/* ── Transitions ──────────────────────────────────────────────────────────── */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 200ms;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
