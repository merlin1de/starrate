<template>
  <div class="sr-guest">

    <!-- Password dialog -->
    <div v-if="passwordDlg" class="sr-guest__overlay">
      <div class="sr-guest__password-dialog">
        <h2 class="sr-guest__password-title">{{ t('starrate', 'Passwortgeschützte Galerie') }}</h2>
        <p class="sr-guest__password-hint">{{ t('starrate', 'Bitte gib das Passwort ein, um die Galerie zu öffnen.') }}</p>
        <input
          v-model="password"
          class="sr-guest__password-input"
          type="password"
          :placeholder="t('starrate', 'Passwort')"
          @keydown.enter="verifyPassword"
        />
        <span v-if="passwordErr" class="sr-guest__password-error">{{ passwordErr }}</span>
        <button class="sr-guest__password-submit" :disabled="!password" @click="verifyPassword">
          {{ t('starrate', 'Bestätigen') }}
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-else-if="loading" class="sr-guest__loading">
      <div class="sr-guest__spinner" />
    </div>

    <!-- Error / expired -->
    <div v-else-if="error" class="sr-guest__error">
      <p>{{ error }}</p>
    </div>

    <!-- Gallery -->
    <div v-else class="sr-guest__gallery">
      <header class="sr-guest__header">
        <h1 class="sr-guest__title">{{ t('starrate', 'Galerie') }}</h1>
        <span v-if="canRate" class="sr-guest__badge">
          {{ t('starrate', 'Bewertung erlaubt') }}
        </span>
      </header>

      <div v-if="images.length === 0" class="sr-guest__empty">
        {{ t('starrate', 'Keine Bilder vorhanden') }}
      </div>

      <div v-else class="sr-guest__grid">
        <div
          v-for="img in filteredImages"
          :key="img.id"
          class="sr-guest__item"
          :data-id="img.id"
        >
          <div class="sr-guest__thumb-wrapper">
            <img
              :src="thumbUrl(img.id)"
              :alt="img.name"
              class="sr-guest__thumb"
              loading="lazy"
            />
            <!-- Color dot -->
            <span
              v-if="img.color"
              class="sr-guest__color-dot"
              :class="`sr-guest__color-dot--${img.color.toLowerCase()}`"
            />
          </div>

          <div class="sr-guest__info">
            <span class="sr-guest__name">{{ img.name }}</span>

            <!-- Rating controls (if canRate) -->
            <div v-if="canRate" class="sr-guest__rate-controls">
              <button
                v-for="star in 5"
                :key="star"
                class="sr-guest__star"
                :class="{ 'sr-guest__star--filled': star <= (pendingRatings[img.id] ?? img.rating) }"
                :title="`${star} ★`"
                @click="rateImage(img.id, star, null)"
              >★</button>
              <button
                class="sr-guest__star sr-guest__star--clear"
                :title="t('starrate', 'Bewertung entfernen')"
                @click="rateImage(img.id, 0, null)"
              >✕</button>
            </div>

            <!-- Display-only rating -->
            <div v-else-if="img.rating > 0" class="sr-guest__rating-display">
              <span
                v-for="star in 5"
                :key="star"
                class="sr-guest__star"
                :class="{ 'sr-guest__star--filled': star <= img.rating }"
              >★</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Toasts -->
    <TransitionGroup name="sr-toast" tag="div" class="sr-guest__toasts">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        class="sr-guest__toast"
        :class="`sr-guest__toast--${toast.type}`"
      >
        {{ toast.message }}
      </div>
    </TransitionGroup>

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// l10n shim (standalone page – Nextcloud l10n may not be loaded)
function t(app, str) {
  if (typeof window.OC?.L10N?.translate === 'function') {
    return window.OC.L10N.translate(app, str)
  }
  return str
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps({
  token:     { type: String,  required: true },
  canRate:   { type: Boolean, default: false },
  minRating: { type: Number,  default: 0 },
})

// ── State ─────────────────────────────────────────────────────────────────────

const images        = ref([])
const loading       = ref(false)
const error         = ref('')
const passwordDlg   = ref(false)
const password      = ref('')
const passwordErr   = ref('')
const pendingRatings = ref({})  // optimistic updates
const toasts        = ref([])
let   toastSeq      = 0

// ── Computed ──────────────────────────────────────────────────────────────────

const filteredImages = computed(() =>
  props.minRating > 0
    ? images.value.filter(img => img.rating >= props.minRating)
    : images.value
)

// ── Helpers ───────────────────────────────────────────────────────────────────

function thumbUrl(fileId) {
  return generateUrl(`/apps/starrate/api/thumbnail/${fileId}`)
}

function showToast(message, type = 'success') {
  const id = ++toastSeq
  toasts.value.push({ id, message, type })
  setTimeout(() => {
    toasts.value = toasts.value.filter(t => t.id !== id)
  }, 4000)
}

// ── API ───────────────────────────────────────────────────────────────────────

async function loadImages() {
  loading.value = true
  error.value   = ''
  try {
    const { data } = await axios.get(
      generateUrl(`/apps/starrate/api/guest/${props.token}/images`)
    )
    images.value = data.images ?? []
  } catch (e) {
    if (e?.response?.status === 401) {
      passwordDlg.value = true
    } else if (e?.response?.status === 404) {
      error.value = t('starrate', 'Dieser Freigabe-Link ist nicht mehr gültig.')
    } else {
      error.value = t('starrate', 'Fehler beim Laden der Galerie.')
    }
  } finally {
    loading.value = false
  }
}

async function verifyPassword() {
  passwordErr.value = ''
  try {
    await axios.post(
      generateUrl(`/apps/starrate/api/guest/${props.token}/verify`),
      { password: password.value }
    )
    passwordDlg.value = false
    password.value    = ''
    await loadImages()
  } catch {
    passwordErr.value = t('starrate', 'Falsches Passwort')
  }
}

async function rateImage(fileId, rating, color) {
  const prev = pendingRatings.value[fileId] ?? images.value.find(i => i.id === fileId)?.rating ?? 0
  pendingRatings.value[fileId] = rating  // optimistic

  try {
    await axios.post(
      generateUrl(`/apps/starrate/api/guest/${props.token}/rate`),
      { file_id: fileId, rating, color, guest_name: '' }
    )
    // Persist into images array
    const img = images.value.find(i => i.id === fileId)
    if (img) img.rating = rating

    showToast(
      rating === 0
        ? t('starrate', 'Bewertung entfernt')
        : t('starrate', 'Bewertung gespeichert'),
      'success'
    )
  } catch {
    pendingRatings.value[fileId] = prev  // rollback
    showToast(t('starrate', 'Bewertung konnte nicht gespeichert werden.'), 'error')
  }
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────

onMounted(loadImages)

// ── Expose (for GuestGallery.spec.js stub compatibility) ──────────────────────

defineExpose({ images, loading, passwordDlg, password, passwordErr, toasts,
               verifyPassword, rateImage })
</script>

<style scoped>
/* Dark base */
.sr-guest {
  min-height: 100vh;
  background: #1a1a2e;
  color: #d4d4d8;
  font-family: 'Inter', system-ui, sans-serif;
  padding: 0;
  position: relative;
}

/* Header */
.sr-guest__header {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.5rem 2rem 1rem;
  border-bottom: 1px solid #2a2a3e;
}
.sr-guest__title {
  font-size: 1.4rem;
  font-weight: 600;
  color: #fff;
  margin: 0;
}
.sr-guest__badge {
  background: #e94560;
  color: #fff;
  font-size: 0.75rem;
  padding: 0.2rem 0.6rem;
  border-radius: 99px;
}

/* Grid */
.sr-guest__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 1rem;
  padding: 1.5rem 2rem;
}

/* Item */
.sr-guest__item {
  background: #16213e;
  border-radius: 8px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.sr-guest__thumb-wrapper {
  position: relative;
  aspect-ratio: 4/3;
  background: #0f0f1a;
  overflow: hidden;
}
.sr-guest__thumb {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.sr-guest__color-dot {
  position: absolute;
  top: 6px;
  right: 6px;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  border: 1px solid rgba(255,255,255,0.4);
}
.sr-guest__color-dot--red    { background: #e94560; }
.sr-guest__color-dot--yellow { background: #f5c518; }
.sr-guest__color-dot--green  { background: #4caf50; }
.sr-guest__color-dot--blue   { background: #2196f3; }
.sr-guest__color-dot--purple { background: #9c27b0; }

/* Info bar */
.sr-guest__info {
  padding: 0.5rem 0.75rem 0.75rem;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.sr-guest__name {
  font-size: 0.8rem;
  color: #a1a1aa;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Stars */
.sr-guest__rate-controls,
.sr-guest__rating-display {
  display: flex;
  gap: 2px;
}
.sr-guest__star {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 1.1rem;
  color: #52525b;
  padding: 0;
  line-height: 1;
  transition: color 0.15s;
}
.sr-guest__star:hover,
.sr-guest__star--filled {
  color: #f5c518;
}
.sr-guest__star--clear {
  color: #71717a;
  font-size: 0.85rem;
  margin-left: 4px;
}
.sr-guest__star--clear:hover { color: #e94560; }

/* Password dialog */
.sr-guest__overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}
.sr-guest__password-dialog {
  background: #16213e;
  border: 1px solid #2a2a3e;
  border-radius: 12px;
  padding: 2rem;
  width: min(400px, 90vw);
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.sr-guest__password-title {
  color: #fff;
  font-size: 1.1rem;
  font-weight: 600;
  margin: 0;
}
.sr-guest__password-hint {
  color: #a1a1aa;
  font-size: 0.875rem;
  margin: 0;
}
.sr-guest__password-input {
  background: #0f0f1a;
  border: 1px solid #3f3f5a;
  border-radius: 6px;
  color: #d4d4d8;
  font-size: 0.9rem;
  padding: 0.5rem 0.75rem;
  width: 100%;
  box-sizing: border-box;
}
.sr-guest__password-input:focus {
  outline: none;
  border-color: #e94560;
}
.sr-guest__password-error {
  color: #e94560;
  font-size: 0.8rem;
}
.sr-guest__password-submit {
  background: #e94560;
  color: #fff;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.9rem;
  padding: 0.5rem 1.5rem;
  align-self: flex-end;
}
.sr-guest__password-submit:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

/* Loading / empty / error */
.sr-guest__loading {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 60vh;
}
.sr-guest__spinner {
  width: 40px; height: 40px;
  border: 3px solid #2a2a3e;
  border-top-color: #e94560;
  border-radius: 50%;
  animation: sr-spin 0.8s linear infinite;
}
@keyframes sr-spin { to { transform: rotate(360deg); } }
.sr-guest__empty,
.sr-guest__error {
  text-align: center;
  padding: 4rem 2rem;
  color: #71717a;
}
.sr-guest__error { color: #e94560; }

/* Toasts */
.sr-guest__toasts {
  position: fixed;
  bottom: 1.5rem;
  right: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  z-index: 2000;
}
.sr-guest__toast {
  background: #16213e;
  border: 1px solid #2a2a3e;
  border-radius: 8px;
  color: #d4d4d8;
  font-size: 0.875rem;
  padding: 0.6rem 1rem;
  min-width: 200px;
  box-shadow: 0 4px 16px rgba(0,0,0,0.4);
}
.sr-guest__toast--success { border-left: 3px solid #4caf50; }
.sr-guest__toast--error   { border-left: 3px solid #e94560; }

.sr-toast-enter-active { transition: all 0.25s ease; }
.sr-toast-leave-active { transition: all 0.2s ease; }
.sr-toast-enter-from   { opacity: 0; transform: translateY(8px); }
.sr-toast-leave-to     { opacity: 0; transform: translateX(20px); }
</style>
