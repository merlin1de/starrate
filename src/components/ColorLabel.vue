<template>
  <div
    class="sr-color-label"
    :class="{ 'sr-color-label--interactive': interactive, 'sr-color-label--compact': compact }"
    role="radiogroup"
    :aria-label="t('starrate', 'Farbmarkierung')"
  >
    <button
      v-for="color in COLORS"
      :key="color.key"
      class="sr-color-label__dot"
      :class="[
        `sr-color-label__dot--${color.key.toLowerCase()}`,
        { 'sr-color-label__dot--active': modelValue === color.key },
        { 'sr-color-label__dot--tapped': tappedKey === color.key },
      ]"
      type="button"
      role="radio"
      :aria-checked="modelValue === color.key"
      :aria-label="t('starrate', color.label)"
      :title="`${t('starrate', color.label)} (${color.shortcut})`"
      :tabindex="interactive ? 0 : -1"
      :disabled="!interactive"
      @click="interactive && toggle(color.key)"
      @pointerup="e => e.pointerType === 'touch' && e.currentTarget.blur()"
      @pointercancel="e => e.pointerType === 'touch' && e.currentTarget.blur()"
      @keydown.prevent="onKeydown($event, color)"
    />

    <!-- Farbe entfernen (immer gerendert, damit Layout stabil bleibt) -->
    <button
      v-if="interactive"
      class="sr-color-label__clear"
      :class="{ 'sr-color-label__clear--hidden': modelValue === null }"
      type="button"
      :tabindex="modelValue !== null ? 0 : -1"
      :aria-label="t('starrate', 'Farbmarkierung entfernen')"
      :title="t('starrate', 'Farbmarkierung entfernen')"
      @click="modelValue !== null && setColor(null)"
      @pointerup="e => e.pointerType === 'touch' && e.currentTarget.blur()"
    >
      <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      </svg>
    </button>
  </div>
</template>

<script>
// Re-export COLORS so consumers can do: import { COLORS } from '@/components/ColorLabel.vue'
export { COLORS } from '@/utils/colors.js'
</script>

<script setup>
import { ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { COLORS } from '@/utils/colors.js'

const props = defineProps({
  /** Aktive Farbe ('Red'|'Yellow'|'Green'|'Blue'|'Purple'|null) */
  modelValue: {
    type: String,
    default: null,
  },
  /** Interaktiv (klickbar) */
  interactive: {
    type: Boolean,
    default: true,
  },
  /** Kompakter Modus (nur aktive Farbe als kleiner Punkt) */
  compact: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:modelValue', 'change'])

const tappedKey = ref(null)
let tapTimer = null

function toggle(colorKey) {
  // Nochmals gleiche Farbe → entfernen
  const newColor = colorKey === props.modelValue ? null : colorKey

  // Ring sofort zeigen (transition: none !important auf --tapped)
  tappedKey.value = colorKey
  clearTimeout(tapTimer)

  // Nach 150ms entfernen → base-Transition box-shadow 600ms greift für Fade-out
  tapTimer = setTimeout(() => { tappedKey.value = null }, 150)

  setColor(newColor)
}

function setColor(colorKey) {
  emit('update:modelValue', colorKey)
  emit('change', colorKey)
}

function onKeydown(e, color) {
  if (e.key === ' ' || e.key === 'Enter') {
    toggle(color.key)
  }
}

// Externes Setzen per Tastatürkürzel (6–9 für Rot–Blau)
function setByShortcut(key) {
  const map = { '6': 'Red', '7': 'Yellow', '8': 'Green', '9': 'Blue' }
  if (map[key]) {
    toggle(map[key])
    return true
  }
  return false
}

defineExpose({ setColor, setByShortcut, COLORS })
</script>

<style scoped>
.sr-color-label {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.sr-color-label--interactive {
  cursor: pointer;
}

.sr-color-label__dot {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  border: 2px solid transparent;
  padding: 0;
  cursor: inherit;
  touch-action: manipulation;
  -webkit-tap-highlight-color: transparent;
  transition: transform 120ms ease;
  outline: none;
  position: relative;
}

.sr-color-label--compact .sr-color-label__dot {
  width: 10px;
  height: 10px;
}

/* Farben */
.sr-color-label__dot--red    { background: #e05252; }
.sr-color-label__dot--yellow { background: #e0c252; }
.sr-color-label__dot--green  { background: #52a852; }
.sr-color-label__dot--blue   { background: #5277e0; }
.sr-color-label__dot--purple { background: #9b52e0; }

/* Hover — nur auf Gerät mit Maus (pointer: fine), nicht auf Touch/Mobile */
@media (pointer: fine) {
  .sr-color-label--interactive .sr-color-label__dot:not(:disabled):hover {
    transform: scale(1.15);
    box-shadow: 0 0 0 2px rgba(255,255,255,0.6), 0 0 0 5px rgba(0,0,0,0.4);
  }
}

/* Touch: Browser-Focus-Border unterdrücken – verhindert Artefakte nach Tap */
@media (pointer: coarse) {
  .sr-color-label__dot:focus:not(.sr-color-label__dot--active) {
    border-color: transparent !important;
  }
}

/* Aktiv (dauerhaft gesetzte Farbe): nur weißer Rand – kein Scale, kein Schatten */
.sr-color-label__dot--active {
  border-color: #fff;
}

/* Tap-Feedback via ::after – isoliert vom base-Element, keine Seiteneffekte */
.sr-color-label__dot::after {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: 50%;
  box-shadow: 0 0 0 5px rgba(255,255,255,0.85), 0 0 0 7px rgba(0,0,0,0.5);
  opacity: 0;
  transition: opacity 600ms ease-in;
  pointer-events: none;
}

/* Instant show: opacity 0ms, Fade-out (600ms) greift beim Entfernen der Klasse */
.sr-color-label__dot--tapped {
  border-color: #fff !important;
  transform: scale(1.15);
}
.sr-color-label__dot--tapped::after {
  opacity: 1;
  transition: opacity 0ms;
}

/* focus-visible nur auf Maus-Geräten – verhindert klebende Ringe nach Touch */
@media (pointer: fine) {
  .sr-color-label__dot:focus-visible {
    box-shadow: 0 0 0 3px #e94560;
  }
}

.sr-color-label__dot:disabled {
  cursor: default;
  opacity: 0.7;
}

.sr-color-label__clear {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 14px;
  height: 14px;
  padding: 0;
  border: none;
  background: transparent !important;
  color: #888;
  cursor: pointer;
  opacity: 0;
  margin-left: 2px;
  transition: opacity 150ms, color 150ms;
  flex-shrink: 0;
}

.sr-color-label__clear--hidden {
  visibility: hidden;
  pointer-events: none;
}

/* Clear-Button nur auf Maus-Hover einblenden – auf Touch würde er nach Tap kleben bleiben */
@media (pointer: fine) {
  .sr-color-label:hover .sr-color-label__clear:not(.sr-color-label__clear--hidden),
  .sr-color-label:focus-within .sr-color-label__clear:not(.sr-color-label__clear--hidden) {
    opacity: 1;
  }
}

.sr-color-label__clear:hover {
  color: #b04060;
  background: transparent !important;
}

.sr-color-label__clear svg {
  width: 100%;
  height: 100%;
}
</style>
