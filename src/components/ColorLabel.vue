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
      ]"
      type="button"
      role="radio"
      :aria-checked="modelValue === color.key"
      :aria-label="color.label"
      :title="`${color.label} (${color.shortcut})`"
      :tabindex="interactive ? 0 : -1"
      :disabled="!interactive"
      @click="interactive && toggle(color.key)"
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

function toggle(colorKey) {
  // Nochmals gleiche Farbe → entfernen
  const newColor = colorKey === props.modelValue ? null : colorKey
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
  width: 18px;
  height: 18px;
  border-radius: 50%;
  border: 2px solid transparent;
  padding: 0;
  cursor: inherit;
  transition: transform 120ms ease, border-color 120ms ease, box-shadow 120ms ease;
  outline: none;
  position: relative;
}

.sr-color-label--compact .sr-color-label__dot {
  width: 10px;
  height: 10px;
  border-width: 1.5px;
}

/* Farben */
.sr-color-label__dot--red    { background: #e05252; }
.sr-color-label__dot--yellow { background: #e0c252; }
.sr-color-label__dot--green  { background: #52a852; }
.sr-color-label__dot--blue   { background: #5277e0; }
.sr-color-label__dot--purple { background: #9b52e0; }

/* Hover */
.sr-color-label--interactive .sr-color-label__dot:not(:disabled):hover {
  transform: scale(1.25);
  box-shadow: 0 0 0 3px rgba(255,255,255,0.15);
}

/* Aktiv */
.sr-color-label__dot--active {
  border-color: #fff;
  box-shadow: 0 0 0 2px rgba(255,255,255,0.8), 0 0 0 4px rgba(0,0,0,0.4);
  transform: scale(1.1);
}

.sr-color-label__dot:focus-visible {
  box-shadow: 0 0 0 3px #e94560;
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

.sr-color-label:hover .sr-color-label__clear:not(.sr-color-label__clear--hidden),
.sr-color-label:focus-within .sr-color-label__clear:not(.sr-color-label__clear--hidden) {
  opacity: 1;
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
