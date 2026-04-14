<template>
  <div class="sr-settings">

    <div class="sr-settings__group">
      <h3 class="sr-settings__heading">{{ t('starrate', 'Anzeige') }}</h3>

      <!-- Spalten -->
      <div class="sr-settings__row">
        <label class="sr-settings__label">{{ t('starrate', 'Spalten') }}</label>
        <div class="sr-settings__control">
          <select v-model="form.grid_columns" class="sr-settings__select" @change="autosave">
            <option value="auto">{{ t('starrate', 'Automatisch') }}</option>
            <option value="2">2</option>
            <option value="3">3</option>
            <option value="4">4</option>
            <option value="5">5</option>
            <option value="6">6</option>
            <option value="8">8</option>
          </select>
        </div>
      </div>

      <!-- Vorschaugröße -->
      <div class="sr-settings__row">
        <label class="sr-settings__label">{{ t('starrate', 'Vorschaugröße') }}</label>
        <div class="sr-settings__control sr-settings__control--slider">
          <input
            v-model.number="form.thumbnail_size"
            type="range" min="120" max="600" step="40"
            class="sr-settings__slider"
            @change="autosave"
          />
          <span class="sr-settings__slider-val">{{ form.thumbnail_size }}px</span>
        </div>
      </div>
    </div>

    <div class="sr-settings__group">
      <h3 class="sr-settings__heading">{{ t('starrate', 'Sortierung') }}</h3>

      <div class="sr-settings__row">
        <label class="sr-settings__label">{{ t('starrate', 'Standard-Sortierung') }}</label>
        <div class="sr-settings__control sr-settings__control--inline">
          <select v-model="form.default_sort" class="sr-settings__select" @change="autosave">
            <option value="name">{{ t('starrate', 'Name') }}</option>
            <option value="mtime">{{ t('starrate', 'Änderungsdatum') }}</option>
            <option value="size">{{ t('starrate', 'Dateigröße') }}</option>
          </select>
          <select v-model="form.default_sort_order" class="sr-settings__select" @change="autosave">
            <option value="asc">{{ t('starrate', 'Aufsteigend') }}</option>
            <option value="desc">{{ t('starrate', 'Absteigend') }}</option>
          </select>
        </div>
      </div>
    </div>

    <div class="sr-settings__group">
      <h3 class="sr-settings__heading">{{ t('starrate', 'Info-Leiste') }}</h3>

      <div class="sr-settings__row">
        <label class="sr-settings__label sr-settings__label--check">
          <input type="checkbox" v-model="form.show_filename" @change="autosave" />
          {{ t('starrate', 'Dateiname anzeigen') }}
        </label>
      </div>
      <div class="sr-settings__row">
        <label class="sr-settings__label sr-settings__label--check">
          <input type="checkbox" v-model="form.show_rating_overlay" @change="autosave" />
          {{ t('starrate', 'Sterne in Info-Leiste') }}
        </label>
      </div>
      <div class="sr-settings__row">
        <label class="sr-settings__label sr-settings__label--check">
          <input type="checkbox" v-model="form.show_color_overlay" @change="autosave" />
          {{ t('starrate', 'Farbpunkt in Info-Leiste') }}
        </label>
      </div>
    </div>

    <div class="sr-settings__group">
      <h3 class="sr-settings__heading">{{ t('starrate', 'Funktionen') }}</h3>
      <div class="sr-settings__row">
        <label class="sr-settings__label sr-settings__label--check">
          <input type="checkbox" v-model="form.enable_pick_ui" @change="autosave" />
          {{ t('starrate', 'Pick / Reject aktivieren') }}
        </label>
      </div>
      <div class="sr-settings__row">
        <label class="sr-settings__label sr-settings__label--check">
          <input type="checkbox" v-model="form.write_xmp" @change="autosave" />
          {{ t('starrate', 'XMP in JPEG schreiben') }}
        </label>
      </div>
      <div class="sr-settings__row">
        <label class="sr-settings__label sr-settings__label--check">
          <input type="checkbox" v-model="form.comments_enabled" @change="autosave" />
          {{ t('starrate', 'Kommentare aktivieren') }}
        </label>
      </div>
    </div>

    <!-- Status -->
    <Transition name="sr-fade">
      <span v-if="status" class="sr-settings__status" :class="`sr-settings__status--${status}`">
        {{ status === 'ok' ? t('starrate', '✓ Gespeichert') : t('starrate', '✗ Fehler beim Speichern') }}
      </span>
    </Transition>

  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const props = defineProps({
  initial: { type: Object, default: () => ({}) },
})

const DEFAULTS = {
  default_sort:        'name',
  default_sort_order:  'asc',
  thumbnail_size:       280,
  show_filename:        true,
  show_rating_overlay:  true,
  show_color_overlay:   true,
  grid_columns:        'auto',
  enable_pick_ui:       false,
  write_xmp:            true,
  comments_enabled:     false,
}

const form   = reactive({ ...DEFAULTS, ...props.initial })
const status = ref('')
let   saveTimer = null

async function autosave() {
  clearTimeout(saveTimer)
  saveTimer = setTimeout(async () => {
    try {
      await axios.post(generateUrl('/apps/starrate/api/settings'), { ...form })
      status.value = 'ok'
    } catch {
      status.value = 'error'
    }
    setTimeout(() => { status.value = '' }, 2500)
  }, 600)
}
</script>

<style scoped>
.sr-settings {
  display: flex;
  flex-direction: column;
  gap: 24px;
  max-width: 480px;
  padding: 4px 0 16px;
}

.sr-settings__group {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.sr-settings__heading {
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--color-text-maxcontrast, #888);
  margin: 0;
}

.sr-settings__row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.sr-settings__label {
  min-width: 170px;
  font-size: 14px;
  color: var(--color-main-text, #222);
}

.sr-settings__label--check {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: unset;
  cursor: pointer;
}

.sr-settings__control {
  display: flex;
  align-items: center;
}

.sr-settings__control--inline {
  gap: 8px;
}

.sr-settings__control--slider {
  gap: 10px;
}

.sr-settings__select {
  padding: 5px 8px;
  border-radius: 5px;
  border: 1px solid var(--color-border, #ccc);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
  font-size: 13px;
  cursor: pointer;
}

.sr-settings__slider {
  width: 160px;
  accent-color: var(--color-primary, #0082c9);
}

.sr-settings__slider-val {
  font-size: 12px;
  color: var(--color-text-maxcontrast, #888);
  min-width: 42px;
}

.sr-settings__status {
  font-size: 13px;
  font-weight: 500;
}

.sr-settings__status--ok    { color: var(--color-success, #46ba61); }
.sr-settings__status--error { color: var(--color-error,   #e9322d); }

.sr-fade-enter-active, .sr-fade-leave-active { transition: opacity 300ms; }
.sr-fade-enter-from, .sr-fade-leave-to       { opacity: 0; }
</style>
