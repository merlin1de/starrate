<template>
  <div class="sr-filterbar">
    <!-- Linke Seite: Filter -->
    <div ref="filtersEl" class="sr-filterbar__filters" role="toolbar" :aria-label="t('starrate', 'Bildfilter')">
      <!-- Trichter-Icon -->
      <svg class="sr-filterbar__funnel" :class="{ 'sr-filterbar__funnel--active': hasActiveFilter }" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M3 4h18l-7 8v6l-4 2V12L3 4z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
      </svg>
      <span class="sr-filterbar__label">{{ t('starrate', 'Filter:') }}</span>

      <!-- Sterne-Filter -->
      <div class="sr-filterbar__group" role="group" :aria-label="t('starrate', 'Sterne')">
        <!-- Operator-Auswahl -->
        <div class="sr-filterbar__op-group">
          <button
            v-for="op in ['≥', '=', '≤']"
            :key="op"
            class="sr-filterbar__op"
            :class="{ 'sr-filterbar__op--active': selectedOp === op }"
            type="button"
            :title="opTitle(op)"
            @click="setOp(op)"
          >{{ op }}</button>
        </div>
        <!-- Stern-Buttons 5→1, dann 0 -->
        <button
          v-for="n in [5, 4, 3, 2, 1]"
          :key="n"
          class="sr-filterbar__pill sr-filterbar__pill--star"
          :class="{ 'sr-filterbar__pill--active': isStarActive(n) }"
          type="button"
          :title="`${selectedOp} ${n}★`"
          @click="clickStars(n)"
        >{{ '★'.repeat(n) }}</button>
        <button
          class="sr-filterbar__pill sr-filterbar__pill--star"
          :class="{ 'sr-filterbar__pill--active': isStarActive(0) }"
          type="button"
          :title="t('starrate', 'Unbewertet')"
          @click="clickStars(0)"
        >○</button>
      </div>

      <div class="sr-filterbar__sep" aria-hidden="true" />

      <!-- Farb-Filter: kleine Kreise -->
      <div class="sr-filterbar__group" role="group" :aria-label="t('starrate', 'Farben')">
        <button
          v-for="color in colorOptions"
          :key="color.key"
          class="sr-filterbar__colordot"
          :class="{ 'sr-filterbar__colordot--active': filter.color === color.key }"
          type="button"
          :aria-pressed="filter.color === color.key"
          :title="t('starrate', color.label)"
          :style="{ background: color.hex }"
          @click="toggleColorFilter(color.key)"
        />
      </div>

      <div class="sr-filterbar__sep" aria-hidden="true" />

      <!-- Pick/Reject-Filter -->
      <div v-if="enablePickUi" class="sr-filterbar__group" role="group" :aria-label="t('starrate', 'Auswahl')">

        <button
          class="sr-filterbar__pill sr-filterbar__pill--pick"
          :class="{ 'sr-filterbar__pill--active': filter.pick === 'pick' }"
          type="button"
          :title="t('starrate', 'Nur Picks anzeigen')"
          @click="togglePickFilter('pick')"
        >
          <svg viewBox="0 0 24 24" fill="none" class="sr-filterbar__pick-icon" aria-hidden="true"><polyline points="20 6 9 17 4 12" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <button
          class="sr-filterbar__pill sr-filterbar__pill--reject"
          :class="{ 'sr-filterbar__pill--active': filter.pick === 'reject' }"
          type="button"
          :title="t('starrate', 'Nur Ablehnungen anzeigen')"
          @click="togglePickFilter('reject')"
        >
          <svg viewBox="0 0 24 24" fill="none" class="sr-filterbar__pick-icon" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><line x1="17" y1="7" x2="7" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </button>
      </div>

      <!-- Mobile-Reset: nur sichtbar wenn Filter aktiv -->
      <button
        v-if="hasActiveFilter"
        class="sr-filterbar__reset sr-filterbar__reset--mobile"
        type="button"
        @click="resetFilters"
      >✕</button>

      <!-- Mobile: Teilen/Export ans Ende der Scroll-Zone (selten genutzt, nicht prominent) -->
      <div
        v-if="allowShare || allowExport"
        class="sr-filterbar__actions sr-filterbar__actions--mobile"
      >
        <div class="sr-filterbar__sep" aria-hidden="true" />
        <button
          v-if="allowShare"
          class="sr-filterbar__action"
          type="button"
          :title="t('starrate', 'Freigabe-Links verwalten')"
          @click="$emit('open-share-list')"
        >{{ t('starrate', 'Teilen') }}</button>
        <button
          v-if="allowExport"
          class="sr-filterbar__action"
          type="button"
          :disabled="!canExport"
          :title="t('starrate', 'Bewertungsliste exportieren')"
          @click="$emit('open-export-modal')"
        >{{ t('starrate', 'Export') }}</button>
      </div>

    </div>

    <!-- Rechte Seite: Count + Reset + Actions + Modus -->
    <div class="sr-filterbar__right">
      <!-- Aktive Filter: Count + Reset (immer gerendert, nur sichtbar wenn aktiv) -->
      <div class="sr-filterbar__status" :style="{ visibility: hasActiveFilter ? 'visible' : 'hidden' }">
        <span class="sr-filterbar__count" aria-live="polite">
          {{ n('starrate', '%n Bild', '%n Bilder', filteredCount) }}
          <span class="sr-filterbar__count-sep">/</span>
          {{ n('starrate', '%n gesamt', '%n gesamt', total) }}
        </span>
        <button
          class="sr-filterbar__reset"
          type="button"
          :aria-label="t('starrate', 'Alle Filter zurücksetzen')"
          :tabindex="hasActiveFilter ? 0 : -1"
          @click="resetFilters"
        >{{ t('starrate', 'Alle anzeigen') }}</button>
      </div>

      <!-- Desktop: Teilen + Export (rechts vom Filter-Count, statisch sichtbar) -->
      <div
        v-if="allowShare || allowExport"
        class="sr-filterbar__actions sr-filterbar__actions--desktop"
      >
        <button
          v-if="allowShare"
          class="sr-filterbar__action"
          type="button"
          :title="t('starrate', 'Freigabe-Links verwalten')"
          @click="$emit('open-share-list')"
        >{{ t('starrate', 'Teilen') }}</button>
        <button
          v-if="allowExport"
          class="sr-filterbar__action"
          type="button"
          :disabled="!canExport"
          :title="t('starrate', 'Bewertungsliste exportieren')"
          @click="$emit('open-export-modal')"
        >{{ t('starrate', 'Export') }}</button>
      </div>

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
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { t, n } from '@nextcloud/l10n'

const COLOR_OPTIONS = [
  { key: 'Red',    label: t('starrate', 'Rot'),   hex: '#e05252' },
  { key: 'Yellow', label: t('starrate', 'Gelb'),  hex: '#e0c252' },
  { key: 'Green',  label: t('starrate', 'Grün'),  hex: '#52a852' },
  { key: 'Blue',   label: t('starrate', 'Blau'),  hex: '#5277e0' },
  { key: 'Purple', label: t('starrate', 'Lila'),  hex: '#9b52e0' },
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
  enablePickUi: {
    type: Boolean,
    default: false,
  },
  allowShare: {
    type: Boolean,
    default: false,
  },
  allowExport: {
    type: Boolean,
    default: false,
  },
  canExport: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:filter', 'toggle-mode', 'open-share-list', 'open-export-modal'])

const colorOptions = COLOR_OPTIONS

// ─── Rating-Filter ────────────────────────────────────────────────────────────

const selectedOp = ref('≥')
const filtersEl  = ref(null)

// Scroll-Hint auf Mobile: einmal pro Session kurz nach rechts wippen, damit
// erkennbar wird, dass die Filter-Zeile horizontal scrollbar ist.
onMounted(async () => {
  await nextTick()
  const el = filtersEl.value
  if (!el) return
  if (typeof window === 'undefined' || !window.matchMedia) return
  if (!window.matchMedia('(max-width: 640px)').matches) return
  if (el.scrollWidth <= el.clientWidth + 4) return
  try {
    if (sessionStorage.getItem('sr-filterbar-hint')) return
    sessionStorage.setItem('sr-filterbar-hint', '1')
  } catch (e) { /* sessionStorage evtl. blockiert */ }

  // Sanft anschubsen und zurück — nicht zu grell
  const steps = [8, 16, 8, 0]
  steps.forEach((x, i) => setTimeout(() => {
    el.scrollTo({ left: x, behavior: 'smooth' })
  }, 400 + i * 220))
})

// selectedOp mit eingehendem Filter synchronisieren (z.B. aus localStorage)
watch(() => props.filter, (f) => {
  if (f.exactRating !== null)   selectedOp.value = '='
  else if (f.minRating > 0)     selectedOp.value = '≥'
  else if (f.maxRating !== null) selectedOp.value = '≤'
}, { immediate: true })

function opTitle(op) {
  return op === '≥' ? t('starrate', 'Mindestens N Sterne')
       : op === '=' ? t('starrate', 'Genau N Sterne')
       :              t('starrate', 'Höchstens N Sterne')
}

// Aktuell aktive Sternzahl aus dem Filter ableiten
const activeStars = computed(() => {
  const f = props.filter
  if (f.exactRating !== null) return f.exactRating
  if (f.minRating > 0)        return f.minRating
  if (f.maxRating !== null)   return f.maxRating
  return null
})

function setOp(op) {
  selectedOp.value = op
  // Bereits aktiver Filter → sofort mit neuem Operator anwenden
  if (activeStars.value !== null) {
    applyRating(activeStars.value, op)
  }
}

function clickStars(n) {
  // Nochmals klicken → deaktivieren
  if (isStarActive(n)) {
    updateFilter({ ...props.filter, minRating: 0, exactRating: null, maxRating: null })
    return
  }
  // n=0 (unrated) is meaningless with ≥ — always use exact match
  applyRating(n, n === 0 ? '=' : selectedOp.value)
}

function applyRating(n, op) {
  updateFilter({
    ...props.filter,
    minRating:   op === '≥' ? n    : 0,
    exactRating: op === '=' ? n    : null,
    maxRating:   op === '≤' ? n    : null,
  })
}

function isStarActive(n) {
  const f = props.filter
  return (
    (selectedOp.value === '≥' && f.minRating === n && n > 0) ||
    (selectedOp.value === '=' && f.exactRating === n) ||
    (selectedOp.value === '≤' && f.maxRating === n)
  )
}

const hasActiveFilter = computed(() =>
  props.filter.minRating > 0 ||
  props.filter.exactRating !== null ||
  props.filter.maxRating !== null ||
  props.filter.color !== null ||
  props.filter.pick !== null
)

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
  selectedOp.value = '≥'
  updateFilter({
    minRating:   0,
    exactRating: null,
    maxRating:   null,
    color:       null,
    pick:        null,
  })
}

function updateFilter(newFilter) {
  emit('update:filter', newFilter)
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

/* ── Trichter-Icon ────────────────────────────────────────────────────────── */
.sr-filterbar__funnel {
  width: 15px;
  height: 15px;
  flex-shrink: 0;
  color: #444;
  transition: color 150ms;
}

.sr-filterbar__funnel--active {
  color: #d08090;
}

/* ── Label ────────────────────────────────────────────────────────────────── */
.sr-filterbar__label {
  font-size: 11px;
  color: #555;
  font-weight: 500;
  flex-shrink: 0;
  letter-spacing: 0.03em;
}

/* ── Operator-Selector ────────────────────────────────────────────────────── */
.sr-filterbar__op-group {
  display: flex;
  background: #1a1a2e;
  border: 1px solid #2a2a4a;
  border-radius: 6px;
  overflow: hidden;
  flex-shrink: 0;
}

.sr-filterbar__op {
  width: 26px;
  height: 26px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  background: transparent;
  color: #666;
  font-size: 13px;
  cursor: pointer;
  transition: background 150ms, color 150ms;
  appearance: none !important;
  -webkit-appearance: none !important;
  box-shadow: none !important;
}

.sr-filterbar__op:focus,
.sr-filterbar__op:focus-visible,
.sr-filterbar__op:active {
  box-shadow: none !important;
  outline: none !important;
}

@media (pointer: fine) { .sr-filterbar__op:hover { color: #aaa; background: #2a2a4a; } }
.sr-filterbar__op--active    { background: #7a1e30; color: #f0b0bb; border-color: #a03050; }

.sr-filterbar__pill--star {
  padding: 1px 6px;
  min-width: 22px;
  text-align: center;
}

/* ── Pills ────────────────────────────────────────────────────────────────── */
.sr-filterbar__pill {
  display: inline-flex !important;
  align-items: center;
  gap: 4px;
  padding: 1px 8px !important;
  border-radius: 20px;
  border: 1px solid #2a2a4a;
  background: transparent !important;
  color: #aaa;
  font-size: 11px;
  line-height: 1 !important;
  font-family: inherit;
  cursor: pointer;
  transition: background 150ms, color 150ms, border-color 150ms;
  white-space: nowrap;
  min-height: 0 !important;
  height: auto !important;
  box-shadow: none !important;
  appearance: none !important;
  -webkit-appearance: none !important;
}

.sr-filterbar__pill:focus,
.sr-filterbar__pill:focus-visible,
.sr-filterbar__pill:active {
  background: transparent !important;
  box-shadow: none !important;
  outline: none !important;
}

.sr-filterbar__pill--active:focus,
.sr-filterbar__pill--active:focus-visible,
.sr-filterbar__pill--active:active {
  background: #2e1a26 !important;
  box-shadow: none !important;
  outline: none !important;
}


@media (pointer: fine) {
  .sr-filterbar__pill:hover {
    background: #2a2a4a;
    color: #ddd;
  }
}

.sr-filterbar__pill--active {
  background: #2e1a26 !important;
  border-color: #7a3050 !important;
  color: #d08090 !important;
}

@media (pointer: fine) {
  .sr-filterbar__pill--active:hover {
    background: #3a2030 !important;
    border-color: #9a4060 !important;
  }
}

.sr-filterbar__pick-icon {
  width: 13px;
  height: 13px;
  display: block;
}

.sr-filterbar__pill--pick.sr-filterbar__pill--active {
  background: #1a2e1a !important;
  border-color: #306030 !important;
  color: #80c080 !important;
}

.sr-filterbar__pill--reject.sr-filterbar__pill--active {
  background: #2e1a1a !important;
  border-color: #7a3030 !important;
  color: #d08080 !important;
}

/* ── Farb-Kreise ──────────────────────────────────────────────────────────── */
.sr-filterbar__colordot {
  width: 13px;
  height: 13px !important;
  min-height: 0 !important;
  border-radius: 50%;
  border: none !important;
  padding: 0 !important;
  cursor: pointer;
  flex-shrink: 0;
  touch-action: manipulation;
  -webkit-tap-highlight-color: transparent;
  transition: transform 120ms, box-shadow 120ms;
  appearance: none !important;
  -webkit-appearance: none !important;
  outline: none !important;
}

/* Hover nur auf Maus – verhindert klebende Zustände auf Touch */
@media (pointer: fine) {
  .sr-filterbar__colordot:hover {
    transform: scale(1.25);
    box-shadow: 0 0 0 2px rgba(255,255,255,0.45) !important;
  }
}

.sr-filterbar__colordot--active {
  transform: scale(1.15);
  box-shadow: 0 0 0 2px #fff, 0 0 0 3px rgba(0,0,0,0.5) !important;
}

/* Browser-Defaults entfernen – nur auf Maus, damit Touch-Feedback nicht unterdrückt wird */
@media (pointer: fine) {
  .sr-filterbar__colordot:focus,
  .sr-filterbar__colordot:focus-visible,
  .sr-filterbar__colordot:active {
    box-shadow: none !important;
    outline: none !important;
  }

  .sr-filterbar__colordot--active:focus,
  .sr-filterbar__colordot--active:focus-visible,
  .sr-filterbar__colordot--active:active {
    box-shadow: 0 0 0 2px #fff, 0 0 0 3px rgba(0,0,0,0.5) !important;
  }
}

/* outline immer entfernen (kein Visueller Browser-Default gewünscht) */
.sr-filterbar__colordot:focus,
.sr-filterbar__colordot:focus-visible {
  outline: none !important;
}

/* ── Reset ────────────────────────────────────────────────────────────────── */
.sr-filterbar__reset {
  padding: 1px 8px;
  border-radius: 20px;
  border: 1px dashed #7a3050;
  background: transparent;
  color: #c06070;
  font-size: 11px;
  font-family: inherit;
  cursor: pointer;
  transition: background 150ms, color 150ms;
}

@media (pointer: fine) {
  .sr-filterbar__reset:hover {
    background: #3a2030;
    border-color: #9a4060;
    color: #e0a0b0;
  }
}

.sr-filterbar__reset--mobile {
  display: none;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  padding: 0;
  font-size: 13px;
  border-radius: 50%;
  width: 26px;
  height: 26px;
  min-height: 0;
  border: none;
  color: #fff;
  background: #7a3050;
  cursor: pointer;
  font-weight: 600;
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

.sr-filterbar__status {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 180px;
  justify-content: flex-end;
}

.sr-filterbar__count {
  font-variant-numeric: tabular-nums;
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

.sr-filterbar__mode-btn:focus,
.sr-filterbar__mode-btn:focus-visible,
.sr-filterbar__mode-btn:active {
  box-shadow: none !important;
  outline: none !important;
}

@media (pointer: fine) {
  .sr-filterbar__mode-btn:hover {
    color: #aaa;
    background: #2a2a4a;
  }
}

.sr-filterbar__mode-btn--active {
  background: #3a1a28 !important;
  color: #d08090 !important;
  border: none !important;
}

.sr-filterbar__mode-btn svg {
  width: 16px;
  height: 16px;
}


/* Desktop: Toggle ist in der Nav-Zeile, hier ausblenden */
@media (pointer: fine) {
  .sr-filterbar__mode { display: none; }
}

/* ── Teilen / Export Actions ──────────────────────────────────────────────── */
.sr-filterbar__actions {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}

.sr-filterbar__action {
  padding: 2px 10px;
  border-radius: 4px;
  border: 1px solid #3f3f5a;
  background: #2a2a3e;
  color: #a1a1aa;
  font-size: 11px;
  font-family: inherit;
  cursor: pointer;
  white-space: nowrap;
  transition: color 150ms, border-color 150ms, background 150ms;
  box-shadow: none !important;
  appearance: none !important;
  -webkit-appearance: none !important;
  min-height: 0;
  line-height: 1.6;
}

@media (pointer: fine) {
  .sr-filterbar__action:hover {
    color: #d4d4d8;
    border-color: #7a3050;
    background: #32323e;
  }
}

.sr-filterbar__action:focus,
.sr-filterbar__action:focus-visible,
.sr-filterbar__action:active {
  outline: none !important;
  box-shadow: none !important;
}

.sr-filterbar__action:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

/* Desktop-Variante: nur auf Maus-Geräten ODER breitem Viewport */
.sr-filterbar__actions--mobile { display: none; }
@media (max-width: 640px) {
  .sr-filterbar__actions--desktop { display: none; }
  .sr-filterbar__actions--mobile  { display: flex; }
}

/* ── Mobile: single row, horizontal scroll ────────────────────────────────── */
@media (max-width: 640px) {
  .sr-filterbar {
    flex-wrap: nowrap;
    padding: 5px 8px;
    gap: 4px;
    min-height: 0;
  }
  .sr-filterbar__filters {
    flex-wrap: nowrap;
    overflow-x: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
  }
  .sr-filterbar__filters::-webkit-scrollbar { display: none; }

  .sr-filterbar__label              { display: none; }
  .sr-filterbar__sep                { display: none; }
  .sr-filterbar__status             { display: none; }
  .sr-filterbar__reset--mobile      { display: inline-flex; }
}
</style>
