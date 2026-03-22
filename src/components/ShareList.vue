<template>
  <Teleport to="body">
    <div class="sr-share-list__overlay" @click.self="$emit('close')">
      <div class="sr-share-list">

        <header class="sr-share-list__header">
          <h2 class="sr-share-list__title">Freigabe-Links</h2>
          <div class="sr-share-list__header-actions">
            <button class="sr-share-list__new-btn" @click="$emit('create')">
              + Neuer Link
            </button>
            <button class="sr-share-list__close" @click="$emit('close')">✕</button>
          </div>
        </header>

        <!-- Loading -->
        <div v-if="loading" class="sr-share-list__loading">
          <div class="sr-share-list__spinner" />
        </div>

        <!-- Leer -->
        <div v-else-if="shares.length === 0" class="sr-share-list__empty">
          Noch keine Freigabe-Links. Erstelle deinen ersten Link.
        </div>

        <!-- Liste -->
        <div v-else class="sr-share-list__items">
          <div
            v-for="share in shares"
            :key="share.token"
            class="sr-share-list__item"
            :class="{ 'sr-share-list__item--inactive': !share.active }"
          >
            <!-- Share-Zeile -->
            <div class="sr-share-list__row">
              <div class="sr-share-list__info">
                <span class="sr-share-list__path">{{ share.nc_path || '/' }}</span>
                <span v-if="share.guest_name" class="sr-share-list__guest-name">{{ share.guest_name }}</span>
                <div class="sr-share-list__meta">
                  <span class="sr-share-list__badge" :class="share.permissions === 'rate' ? 'sr-share-list__badge--rate' : 'sr-share-list__badge--view'">
                    {{ share.permissions === 'rate' ? 'Bewerten' : 'Ansehen' }}
                  </span>
                  <span v-if="share.min_rating > 0" class="sr-share-list__badge sr-share-list__badge--filter">
                    ≥ {{ share.min_rating }} ★
                  </span>
                  <span v-if="share.has_password" class="sr-share-list__badge sr-share-list__badge--pw">
                    🔒
                  </span>
                  <span v-if="share.expires_at" class="sr-share-list__badge" :class="isExpired(share) ? 'sr-share-list__badge--expired' : 'sr-share-list__badge--date'">
                    {{ isExpired(share) ? 'Abgelaufen' : formatDate(share.expires_at) }}
                  </span>
                </div>
                <div class="sr-share-list__token-row">
                  <input
                    class="sr-share-list__link-input"
                    readonly
                    :value="shareUrl(share.token)"
                    @click="$event.target.select()"
                  />
                  <button class="sr-share-list__copy" @click="copyUrl(share.token)" :title="'Link kopieren'">
                    {{ copiedToken === share.token ? '✓' : '⎘' }}
                  </button>
                </div>
              </div>

              <div class="sr-share-list__actions">
                <!-- Aktiv/Inaktiv Toggle -->
                <button
                  class="sr-share-list__action-btn"
                  :class="share.active ? 'sr-share-list__action-btn--active' : 'sr-share-list__action-btn--inactive'"
                  :title="share.active ? 'Link deaktivieren' : 'Link aktivieren'"
                  @click="toggleActive(share)"
                >
                  {{ share.active ? '●' : '○' }}
                </button>
                <!-- Log-Button -->
                <button
                  class="sr-share-list__action-btn"
                  :class="{ 'sr-share-list__action-btn--active': expandedLog === share.token }"
                  title="Bewertungs-Log anzeigen"
                  @click="toggleLog(share.token)"
                >
                  📋
                </button>
                <!-- Löschen -->
                <button
                  class="sr-share-list__action-btn sr-share-list__action-btn--delete"
                  title="Link löschen"
                  @click="deleteShare(share.token)"
                >
                  🗑
                </button>
              </div>
            </div>

            <!-- Log-Bereich (aufklappbar) -->
            <div v-if="expandedLog === share.token" class="sr-share-list__log">
              <div class="sr-share-list__log-header">
                <span class="sr-share-list__log-title">Bewertungs-Log</span>
                <div class="sr-share-list__log-actions">
                  <button
                    class="sr-share-list__log-btn"
                    :disabled="!logs[share.token]?.length"
                    @click="clearLog(share.token)"
                  >
                    Log löschen
                  </button>
                </div>
              </div>

              <div v-if="logsLoading[share.token]" class="sr-share-list__log-loading">Lädt…</div>
              <div v-else-if="!logs[share.token]?.length" class="sr-share-list__log-empty">
                Noch keine Bewertungen über diesen Link.
              </div>
              <div v-else class="sr-share-list__log-entries">
                <div
                  v-for="(entry, i) in logs[share.token]"
                  :key="i"
                  class="sr-share-list__log-entry"
                >
                  <span class="sr-share-list__log-name">{{ entry.guest_name }}</span>
                  <span class="sr-share-list__log-rating">
                    {{ entry.rating !== null ? '★'.repeat(entry.rating) + '☆'.repeat(5 - (entry.rating ?? 0)) : '–' }}
                  </span>
                  <span v-if="entry.color" class="sr-share-list__log-color" :class="`sr-share-list__log-color--${entry.color.toLowerCase()}`">
                    ●
                  </span>
                  <span class="sr-share-list__log-file">{{ entry.filename ?? `#${entry.file_id}` }}</span>
                  <span class="sr-share-list__log-time">{{ formatDateTime(entry.timestamp) }}</span>
                </div>
              </div>
            </div>

          </div>
        </div>

      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

defineProps({
  ncPath: { type: String, default: '/' },
})

const emit = defineEmits(['close', 'create'])

// ── State ─────────────────────────────────────────────────────────────────────

const shares      = ref([])
const loading     = ref(false)
const expandedLog = ref(null)
const logs        = ref({})       // token → log entries
const logsLoading = ref({})
const copiedToken = ref(null)

// ── Helpers ───────────────────────────────────────────────────────────────────

function shareUrl(token) {
  return window.location.origin + generateUrl(`/apps/starrate/guest/${token}`)
}

function formatDate(ts) {
  return new Date(ts * 1000).toLocaleDateString('de-DE')
}

function formatDateTime(ts) {
  return new Date(ts * 1000).toLocaleString('de-DE', {
    day: '2-digit', month: '2-digit', year: '2-digit',
    hour: '2-digit', minute: '2-digit',
  })
}

function isExpired(share) {
  return share.expires_at && share.expires_at < Math.floor(Date.now() / 1000)
}

async function copyUrl(token) {
  try {
    await navigator.clipboard.writeText(shareUrl(token))
    copiedToken.value = token
    setTimeout(() => { copiedToken.value = null }, 2000)
  } catch { /* ignore */ }
}

// ── API ───────────────────────────────────────────────────────────────────────

async function loadShares() {
  loading.value = true
  try {
    const { data } = await axios.get(generateUrl('/apps/starrate/api/share'))
    shares.value = data.shares ?? []
  } finally {
    loading.value = false
  }
}

async function toggleActive(share) {
  try {
    const { data } = await axios.put(
      generateUrl(`/apps/starrate/api/share/${share.token}`),
      { active: !share.active }
    )
    const idx = shares.value.findIndex(s => s.token === share.token)
    if (idx !== -1) shares.value[idx] = data.share
  } catch { /* ignore */ }
}

async function deleteShare(token) {
  if (!confirm('Freigabe-Link wirklich löschen?')) return
  try {
    await axios.delete(generateUrl(`/apps/starrate/api/share/${token}`))
    shares.value = shares.value.filter(s => s.token !== token)
    if (expandedLog.value === token) expandedLog.value = null
  } catch { /* ignore */ }
}

async function toggleLog(token) {
  if (expandedLog.value === token) {
    expandedLog.value = null
    return
  }
  expandedLog.value = token
  if (!logs.value[token]) {
    await loadLog(token)
  }
}

async function loadLog(token) {
  logsLoading.value = { ...logsLoading.value, [token]: true }
  try {
    const { data } = await axios.get(generateUrl(`/apps/starrate/api/share/${token}/log`))
    logs.value = { ...logs.value, [token]: data.log ?? [] }
  } finally {
    logsLoading.value = { ...logsLoading.value, [token]: false }
  }
}

async function clearLog(token) {
  if (!confirm('Bewertungs-Log für diesen Link löschen?')) return
  try {
    await axios.delete(generateUrl(`/apps/starrate/api/share/${token}/log`))
    logs.value = { ...logs.value, [token]: [] }
  } catch { /* ignore */ }
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────

onMounted(loadShares)

// ── Expose für Gallery.vue ────────────────────────────────────────────────────

defineExpose({ loadShares })
</script>

<style scoped>
.sr-share-list__overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.6);
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding-top: 60px;
  z-index: 8900;
}

.sr-share-list {
  background: #16213e;
  border: 1px solid #2a2a3e;
  border-radius: 12px;
  width: min(680px, 96vw);
  max-height: calc(100vh - 80px);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
}

.sr-share-list__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1.25rem 1.5rem 1rem;
  border-bottom: 1px solid #2a2a3e;
  flex-shrink: 0;
}
.sr-share-list__title {
  color: #fff;
  font-size: 1rem;
  font-weight: 600;
  margin: 0;
}
.sr-share-list__header-actions {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}
.sr-share-list__new-btn {
  background: #e94560;
  border: none;
  border-radius: 6px;
  color: #fff;
  cursor: pointer;
  font-size: 0.8rem;
  padding: 0.35rem 0.85rem;
}
.sr-share-list__new-btn:hover { opacity: 0.9; }
.sr-share-list__close {
  background: none;
  border: none;
  color: #71717a;
  cursor: pointer;
  font-size: 1rem;
  padding: 0.25rem;
}
.sr-share-list__close:hover { color: #d4d4d8; }

.sr-share-list__loading,
.sr-share-list__empty {
  padding: 2rem 1.5rem;
  color: #71717a;
  text-align: center;
  font-size: 0.875rem;
}
.sr-share-list__spinner {
  width: 28px; height: 28px;
  border: 2px solid #2a2a3e;
  border-top-color: #e94560;
  border-radius: 50%;
  animation: sr-sl-spin 0.8s linear infinite;
  margin: 0 auto;
}
@keyframes sr-sl-spin { to { transform: rotate(360deg); } }

.sr-share-list__items {
  display: flex;
  flex-direction: column;
}

.sr-share-list__item {
  border-bottom: 1px solid #2a2a3e;
}
.sr-share-list__item:last-child { border-bottom: none; }
.sr-share-list__item--inactive { opacity: 0.5; }

.sr-share-list__row {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  padding: 1rem 1.5rem;
}

.sr-share-list__info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.sr-share-list__path {
  color: #fff;
  font-size: 0.875rem;
  font-weight: 500;
  word-break: break-all;
}
.sr-share-list__guest-name {
  color: #a1a1aa;
  font-size: 0.8rem;
}

.sr-share-list__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.sr-share-list__badge {
  font-size: 0.7rem;
  padding: 0.1rem 0.45rem;
  border-radius: 99px;
  background: #2a2a3e;
  color: #a1a1aa;
}
.sr-share-list__badge--view   { background: #1a2a4a; color: #7eaecf; }
.sr-share-list__badge--rate   { background: #2a1a1a; color: #e94560; }
.sr-share-list__badge--filter { background: #2a2a1a; color: #f5c518; }
.sr-share-list__badge--pw     { background: #2a2a3e; }
.sr-share-list__badge--date   { background: #1a2a1a; color: #7ecf7e; }
.sr-share-list__badge--expired{ background: #3a1a1a; color: #e94560; }

.sr-share-list__token-row {
  display: flex;
  gap: 0.35rem;
  align-items: center;
}
.sr-share-list__link-input {
  flex: 1;
  min-width: 0;
  background: #0f0f1a;
  border: 1px solid #2a2a3e;
  border-radius: 4px;
  color: #52525b;
  font-size: 0.7rem;
  padding: 0.25rem 0.5rem;
}
.sr-share-list__copy {
  background: none;
  border: 1px solid #2a2a3e;
  border-radius: 4px;
  color: #71717a;
  cursor: pointer;
  font-size: 0.8rem;
  padding: 0.2rem 0.4rem;
  flex-shrink: 0;
}
.sr-share-list__copy:hover { color: #d4d4d8; }

.sr-share-list__actions {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  flex-shrink: 0;
}

.sr-share-list__action-btn {
  background: none;
  border: 1px solid #2a2a3e;
  border-radius: 4px;
  color: #71717a;
  cursor: pointer;
  font-size: 0.85rem;
  padding: 0.25rem 0.4rem;
  line-height: 1;
}
.sr-share-list__action-btn:hover { color: #d4d4d8; border-color: #3f3f5a; }
.sr-share-list__action-btn--active { color: #4caf50; border-color: #4caf50; }
.sr-share-list__action-btn--inactive { color: #52525b; }
.sr-share-list__action-btn--delete:hover { color: #e94560; border-color: #e94560; }

/* Log-Bereich */
.sr-share-list__log {
  background: #0f0f1a;
  border-top: 1px solid #2a2a3e;
  padding: 0.75rem 1.5rem 1rem;
}
.sr-share-list__log-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.5rem;
}
.sr-share-list__log-title {
  color: #a1a1aa;
  font-size: 0.75rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.sr-share-list__log-actions { display: flex; gap: 0.5rem; }
.sr-share-list__log-btn {
  background: none;
  border: 1px solid #3f3f5a;
  border-radius: 4px;
  color: #71717a;
  cursor: pointer;
  font-size: 0.7rem;
  padding: 0.2rem 0.5rem;
}
.sr-share-list__log-btn:hover:not(:disabled) { color: #e94560; border-color: #e94560; }
.sr-share-list__log-btn:disabled { opacity: 0.4; cursor: not-allowed; }

.sr-share-list__log-loading,
.sr-share-list__log-empty {
  color: #52525b;
  font-size: 0.8rem;
  padding: 0.5rem 0;
}

.sr-share-list__log-entries {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  max-height: 240px;
  overflow-y: auto;
}
.sr-share-list__log-entry {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.3rem 0;
  border-bottom: 1px solid #1a1a2e;
  font-size: 0.8rem;
}
.sr-share-list__log-entry:last-child { border-bottom: none; }
.sr-share-list__log-name {
  color: #d4d4d8;
  font-weight: 500;
  min-width: 90px;
  flex-shrink: 0;
}
.sr-share-list__log-rating {
  color: #f5c518;
  letter-spacing: -0.05em;
  min-width: 70px;
  flex-shrink: 0;
}
.sr-share-list__log-color {
  font-size: 0.7rem;
  flex-shrink: 0;
}
.sr-share-list__log-color--red    { color: #e94560; }
.sr-share-list__log-color--yellow { color: #f5c518; }
.sr-share-list__log-color--green  { color: #4caf50; }
.sr-share-list__log-color--blue   { color: #2196f3; }
.sr-share-list__log-color--purple { color: #9c27b0; }
.sr-share-list__log-file {
  color: #52525b;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sr-share-list__log-time {
  color: #3f3f5a;
  font-size: 0.7rem;
  white-space: nowrap;
  flex-shrink: 0;
}
</style>
