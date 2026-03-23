<template>
  <Teleport to="body">
    <div class="sr-share-modal__overlay" @click.self="$emit('close')">
      <div class="sr-share-modal">
        <header class="sr-share-modal__header">
          <h2 class="sr-share-modal__title">Neuen Freigabe-Link erstellen</h2>
          <button class="sr-share-modal__close" @click="$emit('close')">✕</button>
        </header>

        <!-- Erfolg: Link anzeigen -->
        <div v-if="createdShare" class="sr-share-modal__success">
          <p class="sr-share-modal__success-label">Dein Freigabe-Link:</p>
          <div class="sr-share-modal__link-row">
            <input
              class="sr-share-modal__link-input"
              readonly
              :value="shareUrl(createdShare.token)"
              @click="$event.target.select()"
            />
            <button class="sr-share-modal__copy-btn" :class="{ 'sr-share-modal__copy-btn--done': copied }" @click="copyUrl">
              {{ copied ? '✓ Kopiert' : 'Kopieren' }}
            </button>
          </div>
          <p class="sr-share-modal__success-hint">
            <strong>Ordner:</strong> {{ createdShare.nc_path }}<br>
            <strong>Berechtigung:</strong> {{ createdShare.permissions === 'rate' ? 'Ansehen + Bewerten' : 'Nur ansehen' }}<br>
            <span v-if="createdShare.min_rating > 0"><strong>Vorfilter:</strong> ≥ {{ createdShare.min_rating }} ★<br></span>
            <span v-if="createdShare.has_password"><strong>Passwortgeschützt</strong><br></span>
            <span v-if="createdShare.expires_at"><strong>Läuft ab:</strong> {{ formatDate(createdShare.expires_at) }}</span>
          </p>
          <button class="sr-share-modal__btn sr-share-modal__btn--secondary" @click="reset">
            Weiteren Link erstellen
          </button>
        </div>

        <!-- Formular -->
        <form v-else class="sr-share-modal__form" @submit.prevent="create">

          <div class="sr-share-modal__field">
            <label class="sr-share-modal__label">Ordner</label>
            <input class="sr-share-modal__input sr-share-modal__input--readonly" readonly :value="ncPath" />
          </div>

          <div class="sr-share-modal__field">
            <label class="sr-share-modal__label">
              Name des Empfängers
              <span v-if="form.permissions === 'rate'" class="sr-share-modal__required">*</span>
              <span v-else class="sr-share-modal__optional">(optional)</span>
            </label>
            <input
              v-model="form.guestName"
              class="sr-share-modal__input"
              type="text"
              placeholder="z.B. Anna, Model 1, Kunde Müller"
              maxlength="60"
            />
          </div>

          <div class="sr-share-modal__field">
            <label class="sr-share-modal__label">Berechtigung</label>
            <div class="sr-share-modal__toggle-group">
              <button
                type="button"
                class="sr-share-modal__toggle"
                :class="{ 'sr-share-modal__toggle--active': form.permissions === 'view' }"
                @click="form.permissions = 'view'"
              >Nur ansehen</button>
              <button
                type="button"
                class="sr-share-modal__toggle"
                :class="{ 'sr-share-modal__toggle--active': form.permissions === 'rate' }"
                @click="form.permissions = 'rate'"
              >Ansehen + Bewerten</button>
            </div>
          </div>

          <div class="sr-share-modal__field">
            <label class="sr-share-modal__label">Vorfilter (Mindest-Bewertung)</label>
            <select class="sr-share-modal__select" v-model="form.minRating">
              <option :value="0">Alle Bilder</option>
              <option :value="1">≥ 1 ★</option>
              <option :value="2">≥ 2 ★</option>
              <option :value="3">≥ 3 ★</option>
              <option :value="4">≥ 4 ★</option>
              <option :value="5">5 ★ (nur Top)</option>
            </select>
          </div>

          <div class="sr-share-modal__field">
            <label class="sr-share-modal__label">Passwort <span class="sr-share-modal__optional">(optional)</span></label>
            <input
              v-model="form.password"
              class="sr-share-modal__input"
              type="password"
              placeholder="Leer lassen = kein Passwort"
              autocomplete="new-password"
            />
          </div>

          <div class="sr-share-modal__field">
            <label class="sr-share-modal__label">Ablaufdatum <span class="sr-share-modal__optional">(optional)</span></label>
            <input
              v-model="form.expiresDate"
              class="sr-share-modal__input"
              type="date"
              :min="todayStr"
            />
          </div>

          <p v-if="formError" class="sr-share-modal__error">{{ formError }}</p>

          <div class="sr-share-modal__actions">
            <button type="button" class="sr-share-modal__btn sr-share-modal__btn--secondary" @click="$emit('close')">
              Abbrechen
            </button>
            <button type="submit" class="sr-share-modal__btn sr-share-modal__btn--primary" :disabled="saving">
              {{ saving ? 'Erstelle…' : 'Link erstellen' }}
            </button>
          </div>
        </form>

      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const props = defineProps({
  ncPath: { type: String, required: true },
})

const emit = defineEmits(['close', 'created'])

// ── Formular-State ────────────────────────────────────────────────────────────

const form = ref({
  guestName:   '',
  permissions: 'rate',
  minRating:   0,
  password:    '',
  expiresDate: '',
})

const saving      = ref(false)
const formError   = ref('')
const createdShare = ref(null)
const copied      = ref(false)

const todayStr = computed(() => new Date().toISOString().split('T')[0])

// ── Helpers ───────────────────────────────────────────────────────────────────

function shareUrl(token) {
  return window.location.origin + generateUrl(`/apps/starrate/guest/${token}`)
}

function formatDate(ts) {
  return new Date(ts * 1000).toLocaleDateString('de-DE')
}

async function copyUrl() {
  try {
    await navigator.clipboard.writeText(shareUrl(createdShare.value.token))
    copied.value = true
    setTimeout(() => { copied.value = false }, 2500)
  } catch {
    // Fallback: select input
  }
}

function reset() {
  createdShare.value = null
  formError.value    = ''
  form.value = { guestName: '', permissions: 'rate', minRating: 0, password: '', expiresDate: '' }
}

// ── Create ────────────────────────────────────────────────────────────────────

async function create() {
  formError.value = ''
  saving.value    = true

  // Pflichtfeld-Check: bei rate muss ein Name angegeben sein
  if (form.value.permissions === 'rate' && !form.value.guestName.trim()) {
    formError.value = 'Bitte einen Namen für den Empfänger eingeben.'
    saving.value    = false
    return
  }

  const body = {
    nc_path:     props.ncPath,
    permissions: form.value.permissions,
    min_rating:  form.value.minRating,
  }

  if (form.value.guestName.trim()) {
    body.guest_name = form.value.guestName.trim()
  }

  if (form.value.password) {
    body.password = form.value.password
  }

  if (form.value.expiresDate) {
    // Datum in Unix-Timestamp (End des Tages, lokale Zeit)
    const d = new Date(form.value.expiresDate + 'T23:59:59')
    body.expires_at = Math.floor(d.getTime() / 1000)
  }

  try {
    const url = generateUrl('/apps/starrate/api/share')
    const { data } = await axios.post(url, body)
    createdShare.value = data.share
    emit('created', data.share)
  } catch (e) {
    formError.value = e?.response?.data?.error ?? 'Fehler beim Erstellen des Links'
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.sr-share-modal__overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9000;
}

.sr-share-modal {
  background: #16213e;
  border: 1px solid #2a2a3e;
  border-radius: 12px;
  width: min(480px, 95vw);
  max-height: 90vh;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
}

.sr-share-modal__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1.25rem 1.5rem 1rem;
  border-bottom: 1px solid #2a2a3e;
  flex-shrink: 0;
}
.sr-share-modal__title {
  color: #fff;
  font-size: 1rem;
  font-weight: 600;
  margin: 0;
}
.sr-share-modal__close {
  background: none;
  border: none;
  color: #71717a;
  cursor: pointer;
  font-size: 1rem;
  padding: 0.25rem;
  line-height: 1;
}
.sr-share-modal__close:hover { color: #d4d4d8; }

.sr-share-modal__form,
.sr-share-modal__success {
  padding: 1.25rem 1.5rem 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.sr-share-modal__field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.sr-share-modal__label {
  font-size: 0.8rem;
  color: #a1a1aa;
  font-weight: 500;
}
.sr-share-modal__optional {
  color: #52525b;
  font-weight: 400;
}
.sr-share-modal__required {
  color: #e94560;
  font-weight: 400;
}

.sr-share-modal__input,
.sr-share-modal__select {
  background: #0f0f1a;
  border: 1px solid #3f3f5a;
  border-radius: 6px;
  color: #d4d4d8;
  font-size: 0.875rem;
  padding: 0.45rem 0.75rem;
  width: 100%;
  box-sizing: border-box;
}
.sr-share-modal__input:focus,
.sr-share-modal__select:focus {
  outline: none;
  border-color: #e94560;
}
.sr-share-modal__input--readonly {
  color: #a1a1aa;
  cursor: default;
}
.sr-share-modal__select option { background: #16213e; }

.sr-share-modal__toggle-group {
  display: flex;
  border: 1px solid #3f3f5a;
  border-radius: 6px;
  overflow: hidden;
}
.sr-share-modal__toggle {
  flex: 1;
  background: none;
  border: none;
  color: #71717a;
  cursor: pointer;
  font-size: 0.875rem;
  padding: 0.45rem 0.5rem;
  transition: background 0.15s, color 0.15s;
}
.sr-share-modal__toggle:not(:last-child) { border-right: 1px solid #3f3f5a; }
.sr-share-modal__toggle--active {
  background: #e94560;
  color: #fff;
}

.sr-share-modal__error {
  color: #e94560;
  font-size: 0.8rem;
  margin: 0;
}

.sr-share-modal__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 0.25rem;
}

.sr-share-modal__btn {
  border: none;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.875rem;
  padding: 0.5rem 1.25rem;
}
.sr-share-modal__btn--primary {
  background: #e94560;
  color: #fff;
}
.sr-share-modal__btn--primary:disabled { opacity: 0.5; cursor: not-allowed; }
.sr-share-modal__btn--secondary {
  background: #2a2a3e;
  color: #a1a1aa;
}
.sr-share-modal__btn--secondary:hover { color: #d4d4d8; }

/* Success view */
.sr-share-modal__success-label {
  color: #a1a1aa;
  font-size: 0.8rem;
  margin: 0;
}
.sr-share-modal__link-row {
  display: flex;
  gap: 0.5rem;
}
.sr-share-modal__link-input {
  flex: 1;
  background: #0f0f1a;
  border: 1px solid #3f3f5a;
  border-radius: 6px;
  color: #d4d4d8;
  font-size: 0.8rem;
  padding: 0.45rem 0.75rem;
  min-width: 0;
}
.sr-share-modal__copy-btn {
  background: #2a2a3e;
  border: 1px solid #3f3f5a;
  border-radius: 6px;
  color: #a1a1aa;
  cursor: pointer;
  font-size: 0.8rem;
  padding: 0.45rem 0.75rem;
  white-space: nowrap;
  flex-shrink: 0;
}
.sr-share-modal__copy-btn--done { color: #4caf50; border-color: #4caf50; }
.sr-share-modal__success-hint {
  background: #0f0f1a;
  border: 1px solid #2a2a3e;
  border-radius: 6px;
  color: #a1a1aa;
  font-size: 0.8rem;
  line-height: 1.6;
  margin: 0;
  padding: 0.75rem;
}
</style>
