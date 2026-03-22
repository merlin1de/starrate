<template>
  <Teleport to="body">
    <Transition name="selection-bar">
      <div class="sr-selbar" role="toolbar" :aria-label="t('starrate', 'Stapel-Bewertung')">

        <!-- Anzahl + Schließen -->
        <div class="sr-selbar__info">
          <span class="sr-selbar__count">
            {{ n('starrate', '%n Bild ausgewählt', '%n Bilder ausgewählt', count) }}
          </span>
          <button
            class="sr-selbar__clear"
            type="button"
            :title="t('starrate', 'Auswahl aufheben (Esc)')"
            @click="$emit('clear')"
          >
            <svg viewBox="0 0 24 24" fill="none"><line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          </button>
        </div>

        <div class="sr-selbar__sep" />

        <!-- Sterne-Buttons -->
        <div class="sr-selbar__section" :aria-label="t('starrate', 'Bewertung setzen')">
          <span class="sr-selbar__label">{{ t('starrate', 'Bewertung:') }}</span>
          <button
            v-for="star in [0, 1, 2, 3, 4, 5]"
            :key="`star-${star}`"
            class="sr-selbar__btn sr-selbar__btn--star"
            :class="{ 'sr-selbar__btn--active': lastRating === star && star > 0 }"
            type="button"
            :title="star === 0 ? t('starrate', 'Bewertung entfernen') : n('starrate', '%n Stern', '%n Sterne', star)"
            @click="applyRating(star)"
          >
            <template v-if="star === 0">✕</template>
            <template v-else>{{ '★'.repeat(star) }}</template>
          </button>
        </div>

        <div class="sr-selbar__sep" />

        <!-- Farb-Buttons -->
        <div class="sr-selbar__section" :aria-label="t('starrate', 'Farbe setzen')">
          <span class="sr-selbar__label">{{ t('starrate', 'Farbe:') }}</span>
          <button
            v-for="color in COLORS"
            :key="color.key"
            class="sr-selbar__btn sr-selbar__btn--color"
            :class="{ 'sr-selbar__btn--active': lastColor === color.key }"
            type="button"
            :title="`${color.label} (${color.shortcut})`"
            :style="{ '--dot-color': color.hex }"
            @click="applyColor(color.key)"
          >
            <span class="sr-selbar__color-dot" />
          </button>
          <!-- Farbe entfernen -->
          <button
            class="sr-selbar__btn"
            :class="{ 'sr-selbar__btn--active': lastColor === null && colorApplied }"
            type="button"
            :title="t('starrate', 'Farbe entfernen')"
            @click="applyColor(null)"
          >✕</button>
        </div>

      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref } from 'vue'
import { t, n } from '@nextcloud/l10n'
import { COLORS } from './ColorLabel.vue'

const props = defineProps({
  count: {
    type: Number,
    required: true,
  },
})

const emit = defineEmits(['rate', 'clear'])

const lastRating    = ref(null)
const lastColor     = ref(null)
const colorApplied  = ref(false)

function applyRating(star) {
  lastRating.value = star
  emit('rate', star, null)
}

function applyColor(colorKey) {
  lastColor.value   = colorKey
  colorApplied.value = true
  emit('rate', null, colorKey)
}
</script>

<style scoped>
.sr-selbar {
  position: fixed;
  bottom: max(28px, calc(20px + env(safe-area-inset-bottom)));
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  gap: 0;
  background: #12122a;
  border: 1px solid #e9456040;
  border-radius: 10px;
  box-shadow: 0 8px 32px rgba(0,0,0,0.6), 0 0 0 1px rgba(233,69,96,0.15);
  padding: 8px 14px;
  z-index: 1000;
  backdrop-filter: blur(12px);
  white-space: nowrap;
}

.sr-selbar__info {
  display: flex;
  align-items: center;
  gap: 8px;
}

.sr-selbar__count {
  font-size: 13px;
  font-weight: 600;
  color: #e94560;
}

.sr-selbar__clear {
  width: 22px;
  height: 22px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid #333;
  border-radius: 4px;
  background: transparent;
  color: #777;
  cursor: pointer;
  padding: 0;
  transition: background 150ms, color 150ms;
}

.sr-selbar__clear:hover {
  background: #2a2a4a;
  color: #e94560;
}

.sr-selbar__clear svg { width: 14px; height: 14px; }

.sr-selbar__sep {
  width: 1px;
  height: 28px;
  background: #2a2a4a;
  margin: 0 12px;
  flex-shrink: 0;
}

.sr-selbar__section {
  display: flex;
  align-items: center;
  gap: 5px;
}

.sr-selbar__label {
  font-size: 11px;
  color: #555;
  margin-right: 2px;
}

/* Buttons */
.sr-selbar__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 30px;
  height: 28px;
  padding: 0 6px;
  border: 1px solid #2a2a4a;
  border-radius: 5px;
  background: transparent;
  color: #888;
  font-size: 12px;
  cursor: pointer;
  transition: background 120ms, color 120ms, border-color 120ms;
}

.sr-selbar__btn:hover {
  background: #2a2a4a;
  color: #ddd;
  border-color: #3a3a5a;
}

.sr-selbar__btn--active {
  background: #e94560;
  border-color: #e94560;
  color: #fff;
}

.sr-selbar__btn--star {
  letter-spacing: -1px;
  font-size: 11px;
  color: #f5a623;
}

.sr-selbar__btn--star:first-of-type {
  color: #777;
}

/* Farbpunkt-Button */
.sr-selbar__btn--color {
  width: 28px;
  min-width: 28px;
  padding: 0;
}

.sr-selbar__color-dot {
  width: 13px;
  height: 13px;
  border-radius: 50%;
  background: var(--dot-color, #888);
  display: block;
}

.sr-selbar__btn--color.sr-selbar__btn--active {
  background: transparent;
  border-color: #fff;
  box-shadow: 0 0 0 2px var(--dot-color, #888);
}

/* Slide-up Transition */
.selection-bar-enter-active,
.selection-bar-leave-active {
  transition: transform 250ms cubic-bezier(0.34, 1.56, 0.64, 1), opacity 200ms ease;
}

.selection-bar-enter-from,
.selection-bar-leave-to {
  transform: translateX(-50%) translateY(20px);
  opacity: 0;
}
</style>
