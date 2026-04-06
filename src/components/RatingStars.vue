<template>
  <div
    class="sr-stars"
    :class="{ 'sr-stars--interactive': interactive, 'sr-stars--compact': compact }"
    role="radiogroup"
    :aria-label="t('starrate', 'Rating')"
    @pointerleave="e => e.pointerType === 'mouse' && (hoverRating = 0)"
    @keydown="onKeydown"
    :tabindex="interactive ? 0 : -1"
  >
    <button
      v-for="star in 5"
      :key="star"
      class="sr-stars__star"
      :class="{
        'sr-stars__star--filled':  star <= displayRating,
        'sr-stars__star--hover':   star <= hoverRating && hoverRating > 0,
        'sr-stars__star--partial': star === displayRating && !Number.isInteger(modelValue),
      }"
      type="button"
      role="radio"
      :aria-checked="star === modelValue"
      :aria-label="n('starrate', '%n star', '%n stars', star)"
      :tabindex="-1"
      :disabled="!interactive"
      @pointerenter="e => interactive && e.pointerType === 'mouse' && (hoverRating = star)"
      @click="interactive && setRating(star)"
      @pointerup="e => e.pointerType === 'touch' && e.currentTarget.blur()"
    >
      <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path
          d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"
          :class="star <= displayRating ? 'filled' : 'empty'"
        />
      </svg>
    </button>

    <!-- Löschen-Button (immer gerendert um Layout-Shift zu vermeiden, wie ColorLabel) -->
    <button
      v-if="interactive"
      class="sr-stars__clear"
      :class="{ 'sr-stars__clear--hidden': modelValue === 0 }"
      type="button"
      :tabindex="modelValue > 0 ? 0 : -1"
      :aria-label="t('starrate', 'Remove rating')"
      :title="t('starrate', 'Remove rating')"
      @click="modelValue > 0 && setRating(0)"
    >
      <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      </svg>
    </button>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { t, n } from '@nextcloud/l10n'

const props = defineProps({
  /** Aktuelle Bewertung 0–5 */
  modelValue: {
    type: Number,
    default: 0,
    validator: v => v >= 0 && v <= 5,
  },
  /** Interaktiv (klickbar) oder nur Anzeige */
  interactive: {
    type: Boolean,
    default: true,
  },
  /** Kompakter Modus (kleinere Sterne, z. B. auf Thumbnails) */
  compact: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:modelValue', 'change'])

const hoverRating = ref(0)

const displayRating = computed(() =>
  hoverRating.value > 0 ? hoverRating.value : props.modelValue
)

function setRating(star) {
  // Nochmals auf gleichen Stern klicken → Bewertung entfernen
  const newRating = star === props.modelValue ? 0 : star
  emit('update:modelValue', newRating)
  emit('change', newRating)
}

/** Tastaturkürzel: 0–5 setzen Rating direkt */
function onKeydown(e) {
  if (!props.interactive) return
  const key = e.key
  if (key >= '0' && key <= '5') {
    e.preventDefault()
    setRating(parseInt(key))
  }
}

// Exponiere für externe Tastatursteuerung (Eltern-Komponente ruft auf)
defineExpose({ setRating })
</script>

<style scoped>
.sr-stars {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  outline: none;
}

.sr-stars--interactive {
  cursor: pointer;
}

.sr-stars__star {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  padding: 0;
  border: none;
  background: transparent;
  cursor: inherit;
  color: inherit;
  transition: transform 100ms ease;
}

.sr-stars--compact .sr-stars__star {
  width: 14px;
  height: 14px;
}

.sr-stars__star:disabled {
  cursor: default;
  background: transparent !important;
  opacity: 1 !important;
}

@media (pointer: fine) {
  .sr-stars__star:not(:disabled):hover {
    transform: scale(1.2);
  }
}

.sr-stars__star svg {
  width: 100%;
  height: 100%;
}

.sr-stars__star svg .filled {
  fill: #f5a623;
  stroke: #f5a623;
  stroke-width: 1;
}

.sr-stars__star svg .empty {
  fill: none;
  stroke: #555;
  stroke-width: 1.5;
}

.sr-stars__star--hover svg .empty,
.sr-stars__star--filled svg .empty {
  /* filled via .filled class */
}

/* Hover-Vorschau: alle Sterne bis Hover-Position aufleuchten */
.sr-stars--interactive:hover .sr-stars__star--hover svg .empty {
  fill: rgba(245, 166, 35, 0.5);
  stroke: #f5a623;
}

.sr-stars__clear {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  margin-left: 4px;
  padding: 0;
  border: none;
  background: transparent;
  color: #888;
  cursor: pointer;
  opacity: 0;
  transition: opacity 150ms, color 150ms;
}

.sr-stars:hover .sr-stars__clear,
.sr-stars:focus-within .sr-stars__clear {
  opacity: 1;
}

.sr-stars__clear--hidden {
  visibility: hidden;
  pointer-events: none;
}

.sr-stars__clear:hover {
  color: #e94560;
}

.sr-stars__clear svg {
  width: 100%;
  height: 100%;
}
</style>
