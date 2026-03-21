<template>
  <div class="sr-sync">

    <!-- Header -->
    <div class="sr-sync__header">
      <h2 class="sr-sync__title">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M23 4v6h-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M1 20v-6h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        {{ t('starrate', 'Lightroom Sync') }}
      </h2>
      <button class="sr-sync__add-btn" type="button" @click="openAddDialog">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <line x1="12" y1="5" x2="12" y2="19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          <line x1="5" y1="12" x2="19" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
        {{ t('starrate', 'Zuordnung hinzufügen') }}
      </button>
    </div>

    <!-- Leer-Zustand -->
    <div v-if="!loading && mappings.length === 0" class="sr-sync__empty">
      <svg viewBox="0 0 64 64" fill="none">
        <circle cx="32" cy="32" r="28" stroke="#333" stroke-width="2"/>
        <path d="M32 18v14l8 8" stroke="#444" stroke-width="2" stroke-linecap="round"/>
      </svg>
      <p>{{ t('starrate', 'Noch keine Sync-Zuordnungen') }}</p>
      <p class="sr-sync__empty-sub">{{ t('starrate', 'Verbinde einen Nextcloud-Ordner mit deinem lokalen Lightroom-Ordner') }}</p>
    </div>

    <!-- Lade-Skeleton -->
    <div v-else-if="loading" class="sr-sync__list">
      <div v-for="i in 2" :key="`sk-${i}`" class="sr-sync__item sr-sync__item--skeleton">
        <div class="sr-sync__skeleton-line" style="width:60%" />
        <div class="sr-sync__skeleton-line" style="width:40%;margin-top:6px" />
      </div>
    </div>

    <!-- Zuordnungsliste -->
    <div v-else class="sr-sync__list">
      <div
        v-for="mapping in mappings"
        :key="mapping.id"
        class="sr-sync__item"
        :class="`sr-sync__item--${mapping.status}`"
      >
        <!-- Status-Indikator -->
        <div class="sr-sync__status-dot" :title="statusLabel(mapping.status)" aria-hidden="true" />

        <!-- Pfad-Info -->
        <div class="sr-sync__paths">
          <div class="sr-sync__path-row">
            <span class="sr-sync__path-badge sr-sync__path-badge--nc">NC</span>
            <span class="sr-sync__path" :title="mapping.nc_path">{{ mapping.nc_path }}</span>
          </div>
          <div class="sr-sync__arrow" aria-hidden="true">
            <svg viewBox="0 0 24 6" fill="none">
              <path d="M0 3h22M19 1l3 2-3 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <div class="sr-sync__path-row">
            <span class="sr-sync__path-badge sr-sync__path-badge--lr">LR</span>
            <span class="sr-sync__path" :title="mapping.local_path">{{ mapping.local_path }}</span>
          </div>
        </div>

        <!-- Richtung + letzter Sync -->
        <div class="sr-sync__meta">
          <span class="sr-sync__direction">
            {{ directionLabel(mapping.direction) }}
          </span>
          <span class="sr-sync__last-sync" :title="lastSyncFull(mapping.last_sync)">
            {{ lastSyncRelative(mapping.last_sync) }}
          </span>
        </div>

        <!-- Aktionen -->
        <div class="sr-sync__actions">
          <button
            class="sr-sync__btn sr-sync__btn--sync"
            type="button"
            :disabled="syncing[mapping.id]"
            :title="t('starrate', 'Sync starten')"
            @click="runSync(mapping)"
          >
            <svg viewBox="0 0 24 24" fill="none" :class="{ 'spinning': syncing[mapping.id] }">
              <path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span>{{ syncing[mapping.id] ? t('starrate', 'Lädt…') : t('starrate', 'Sync') }}</span>
          </button>

          <button
            class="sr-sync__btn sr-sync__btn--log"
            type="button"
            :title="t('starrate', 'Log anzeigen')"
            :class="{ 'sr-sync__btn--active': openLog === mapping.id }"
            @click="toggleLog(mapping.id)"
          >
            <svg viewBox="0 0 24 24" fill="none">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
              <polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
              <line x1="8" y1="13" x2="16" y2="13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
              <line x1="8" y1="17" x2="12" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </button>

          <button
            class="sr-sync__btn sr-sync__btn--edit"
            type="button"
            :title="t('starrate', 'Bearbeiten')"
            @click="openEditDialog(mapping)"
          >
            <svg viewBox="0 0 24 24" fill="none">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>

          <button
            class="sr-sync__btn sr-sync__btn--delete"
            type="button"
            :title="t('starrate', 'Löschen')"
            @click="confirmDelete(mapping)"
          >
            <svg viewBox="0 0 24 24" fill="none">
              <polyline points="3 6 5 6 21 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M10 11v6M14 11v6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
              <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
            </svg>
          </button>
        </div>

        <!-- Log-Einträge (aufklappbar) -->
        <Transition name="log-expand">
          <div v-if="openLog === mapping.id && mapping.log?.length" class="sr-sync__log">
            <div class="sr-sync__log-title">{{ t('starrate', 'Letzter Sync-Log') }}</div>
            <div
              v-for="(entry, i) in mapping.log"
              :key="i"
              class="sr-sync__log-entry"
              :class="{
                'sr-sync__log-entry--error': entry.includes('FEHLER'),
                'sr-sync__log-entry--info':  !entry.includes('FEHLER'),
              }"
            >{{ entry }}</div>
          </div>
        </Transition>
      </div>
    </div>

    <!-- ── Dialog: Zuordnung hinzufügen / bearbeiten ────────────────────── -->
    <Teleport to="body">
      <Transition name="dialog">
        <div v-if="dialog.open" class="sr-dialog-backdrop" @click.self="dialog.open = false">
          <div class="sr-dialog" role="dialog" :aria-label="dialogTitle" aria-modal="true">
            <div class="sr-dialog__header">
              <h3>{{ dialogTitle }}</h3>
              <button class="sr-dialog__close" type="button" @click="dialog.open = false">✕</button>
            </div>

            <div class="sr-dialog__body">
              <!-- Nextcloud-Pfad -->
              <label class="sr-dialog__field">
                <span class="sr-dialog__label">{{ t('starrate', 'Nextcloud-Ordner') }}</span>
                <input
                  v-model="dialog.nc_path"
                  class="sr-dialog__input"
                  type="text"
                  :placeholder="t('starrate', 'z. B. /Fotos/Shooting-2024')"
                  @keydown.enter="saveDialog"
                />
                <span class="sr-dialog__hint">{{ t('starrate', 'Pfad relativ zu deinem Nextcloud-Home-Ordner') }}</span>
              </label>

              <!-- Lokaler Pfad -->
              <label class="sr-dialog__field">
                <span class="sr-dialog__label">{{ t('starrate', 'Lokaler Lightroom-Ordner') }}</span>
                <input
                  v-model="dialog.local_path"
                  class="sr-dialog__input"
                  type="text"
                  :placeholder="t('starrate', 'z. B. /Users/foto/Pictures/2024')"
                  @keydown.enter="saveDialog"
                />
                <span class="sr-dialog__hint">{{ t('starrate', 'Absoluter Pfad zum lokalen Ordner mit RAW- und JPEG-Dateien') }}</span>
              </label>

              <!-- Sync-Richtung -->
              <div class="sr-dialog__field">
                <span class="sr-dialog__label">{{ t('starrate', 'Sync-Richtung') }}</span>
                <div class="sr-dialog__radio-group">
                  <label
                    v-for="dir in DIRECTIONS"
                    :key="dir.value"
                    class="sr-dialog__radio"
                    :class="{ 'sr-dialog__radio--active': dialog.direction === dir.value }"
                  >
                    <input
                      type="radio"
                      :value="dir.value"
                      v-model="dialog.direction"
                      class="sr-visually-hidden"
                    />
                    <span class="sr-dialog__radio-icon" v-html="dir.icon" />
                    <span>{{ dir.label }}</span>
                  </label>
                </div>
              </div>
            </div>

            <div class="sr-dialog__footer">
              <button class="sr-dialog__btn sr-dialog__btn--cancel" type="button" @click="dialog.open = false">
                {{ t('starrate', 'Abbrechen') }}
              </button>
              <button
                class="sr-dialog__btn sr-dialog__btn--save"
                type="button"
                :disabled="!dialog.nc_path || !dialog.local_path"
                @click="saveDialog"
              >
                {{ dialog.editId ? t('starrate', 'Speichern') : t('starrate', 'Hinzufügen') }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// ─── Konstanten ────────────────────────────────────────────────────────────────

const DIRECTIONS = [
  {
    value: 'nc_to_lr',
    label: t('starrate', 'Nextcloud → Lightroom'),
    icon: '→',
  },
  {
    value: 'lr_to_nc',
    label: t('starrate', 'Lightroom → Nextcloud'),
    icon: '←',
  },
  {
    value: 'bidirectional',
    label: t('starrate', 'Bidirektional (empfohlen)'),
    icon: '⇄',
  },
]

const STATUS_LABELS = {
  ok:      t('starrate', 'Synchronisiert'),
  pending: t('starrate', 'Ausstehend'),
  error:   t('starrate', 'Fehler'),
  never:   t('starrate', 'Noch nicht synchronisiert'),
}

const DIRECTION_LABELS = {
  nc_to_lr:      'NC → LR',
  lr_to_nc:      'LR → NC',
  bidirectional: '⇄ Bidirektional',
}

// ─── Zustand ──────────────────────────────────────────────────────────────────

const mappings = ref([])
const loading  = ref(false)
const syncing  = ref({})  // { [mappingId]: boolean }
const openLog  = ref(null)

const dialog = reactive({
  open:       false,
  editId:     null,
  nc_path:    '',
  local_path: '',
  direction:  'bidirectional',
})

const emit = defineEmits(['toast'])

// ─── Daten laden ──────────────────────────────────────────────────────────────

onMounted(loadMappings)

async function loadMappings() {
  loading.value = true
  try {
    const { data } = await axios.get(generateUrl('/apps/starrate/api/sync/mappings'))
    mappings.value = (data.mappings ?? []).map(m => ({ ...m }))
  } catch (e) {
    emit('toast', t('starrate', 'Zuordnungen konnten nicht geladen werden'), 'error')
  } finally {
    loading.value = false
  }
}

// ─── Sync ausführen ───────────────────────────────────────────────────────────

async function runSync(mapping) {
  syncing.value = { ...syncing.value, [mapping.id]: true }
  try {
    const { data } = await axios.post(generateUrl(`/apps/starrate/api/sync/run/${mapping.id}`))

    // Status in der Liste sofort aktualisieren
    const idx = mappings.value.findIndex(m => m.id === mapping.id)
    if (idx >= 0) {
      mappings.value[idx].status    = data.errors > 0 ? 'error' : 'ok'
      mappings.value[idx].last_sync = Math.floor(Date.now() / 1000)
      mappings.value[idx].log       = data.log ?? []
    }

    const msg = t('starrate', 'Sync abgeschlossen: {synced} synchronisiert, {errors} Fehler', {
      synced: data.synced,
      errors: data.errors,
    })
    emit('toast', msg, data.errors > 0 ? 'warning' : 'success')

    // Log automatisch öffnen wenn Fehler
    if (data.errors > 0) openLog.value = mapping.id

  } catch (e) {
    emit('toast', t('starrate', 'Sync fehlgeschlagen'), 'error')
  } finally {
    syncing.value = { ...syncing.value, [mapping.id]: false }
  }
}

// ─── Dialog ───────────────────────────────────────────────────────────────────

const dialogTitle = computed(() =>
  dialog.editId
    ? t('starrate', 'Zuordnung bearbeiten')
    : t('starrate', 'Neue Zuordnung')
)

function openAddDialog() {
  dialog.open       = true
  dialog.editId     = null
  dialog.nc_path    = ''
  dialog.local_path = ''
  dialog.direction  = 'bidirectional'
}

function openEditDialog(mapping) {
  dialog.open       = true
  dialog.editId     = mapping.id
  dialog.nc_path    = mapping.nc_path
  dialog.local_path = mapping.local_path
  dialog.direction  = mapping.direction
}

async function saveDialog() {
  if (!dialog.nc_path || !dialog.local_path) return

  const payload = {
    nc_path:    dialog.nc_path.trim(),
    local_path: dialog.local_path.trim(),
    direction:  dialog.direction,
  }

  try {
    if (dialog.editId) {
      const { data } = await axios.put(
        generateUrl(`/apps/starrate/api/sync/mappings/${dialog.editId}`),
        payload
      )
      const idx = mappings.value.findIndex(m => m.id === dialog.editId)
      if (idx >= 0) mappings.value[idx] = data.mapping
      emit('toast', t('starrate', 'Zuordnung aktualisiert'), 'success')
    } else {
      const { data } = await axios.post(generateUrl('/apps/starrate/api/sync/mappings'), payload)
      mappings.value.push(data.mapping)
      emit('toast', t('starrate', 'Zuordnung hinzugefügt'), 'success')
    }
    dialog.open = false
  } catch (e) {
    emit('toast', t('starrate', 'Fehler beim Speichern'), 'error')
  }
}

// ─── Löschen ──────────────────────────────────────────────────────────────────

async function confirmDelete(mapping) {
  const label = `${mapping.nc_path} ↔ ${mapping.local_path}`
  if (!window.confirm(t('starrate', 'Zuordnung "{label}" wirklich löschen?', { label }))) return

  try {
    await axios.delete(generateUrl(`/apps/starrate/api/sync/mappings/${mapping.id}`))
    mappings.value = mappings.value.filter(m => m.id !== mapping.id)
    emit('toast', t('starrate', 'Zuordnung gelöscht'), 'success')
  } catch (e) {
    emit('toast', t('starrate', 'Fehler beim Löschen'), 'error')
  }
}

// ─── Log ──────────────────────────────────────────────────────────────────────

function toggleLog(id) {
  openLog.value = openLog.value === id ? null : id
}

// ─── Hilfsfunktionen ─────────────────────────────────────────────────────────

function statusLabel(status) {
  return STATUS_LABELS[status] ?? status
}

function directionLabel(dir) {
  return DIRECTION_LABELS[dir] ?? dir
}

function lastSyncRelative(ts) {
  if (!ts) return t('starrate', 'Noch nie')
  const diff = Math.floor(Date.now() / 1000) - ts
  if (diff < 60)     return t('starrate', 'Gerade eben')
  if (diff < 3600)   return t('starrate', 'Vor {m} Min.', { m: Math.floor(diff / 60) })
  if (diff < 86400)  return t('starrate', 'Vor {h} Std.', { h: Math.floor(diff / 3600) })
  return t('starrate', 'Vor {d} Tagen', { d: Math.floor(diff / 86400) })
}

function lastSyncFull(ts) {
  if (!ts) return ''
  return new Date(ts * 1000).toLocaleString()
}
</script>

<style scoped>
.sr-sync {
  padding: 20px 24px;
  max-width: 860px;
  margin: 0 auto;
  font-family: 'Inter', system-ui, -apple-system, sans-serif;
  color: #e0e0e0;
}

/* ── Header ───────────────────────────────────────────────────────────────── */
.sr-sync__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
}

.sr-sync__title {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 18px;
  font-weight: 600;
  color: #ddd;
  margin: 0;
}

.sr-sync__title svg {
  width: 20px;
  height: 20px;
  color: #e94560;
}

.sr-sync__add-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  background: #e94560;
  border: none;
  border-radius: 6px;
  color: #fff;
  font-size: 13px;
  font-family: inherit;
  font-weight: 500;
  cursor: pointer;
  transition: background 150ms;
}

.sr-sync__add-btn:hover { background: #c73550; }
.sr-sync__add-btn svg { width: 16px; height: 16px; }

/* ── Leer-Zustand ─────────────────────────────────────────────────────────── */
.sr-sync__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 60px 20px;
  color: #555;
  gap: 10px;
  text-align: center;
}

.sr-sync__empty svg { width: 64px; height: 64px; }
.sr-sync__empty p   { margin: 0; font-size: 14px; }
.sr-sync__empty-sub { font-size: 12px !important; color: #444; }

/* ── Liste ────────────────────────────────────────────────────────────────── */
.sr-sync__list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

/* ── Item ─────────────────────────────────────────────────────────────────── */
.sr-sync__item {
  background: #1e1e2e;
  border: 1px solid #2a2a4a;
  border-radius: 8px;
  padding: 14px 16px;
  display: grid;
  grid-template-columns: 12px 1fr auto auto;
  grid-template-rows: auto auto;
  align-items: center;
  gap: 10px 14px;
  transition: border-color 200ms;
}

.sr-sync__item--ok      { border-left: 3px solid #52a852; }
.sr-sync__item--error   { border-left: 3px solid #e05252; }
.sr-sync__item--pending { border-left: 3px solid #e0c252; }
.sr-sync__item--never   { border-left: 3px solid #444; }

/* Skeleton */
.sr-sync__item--skeleton {
  border-left: 3px solid #2a2a4a;
  pointer-events: none;
}

.sr-sync__skeleton-line {
  height: 14px;
  background: linear-gradient(90deg, #1a1a2e 25%, #2a2a4e 50%, #1a1a2e 75%);
  background-size: 400% 100%;
  animation: shimmer 1.5s infinite;
  border-radius: 4px;
}

@keyframes shimmer {
  0%   { background-position: 100% 0; }
  100% { background-position: 0 0; }
}

/* Status-Dot */
.sr-sync__status-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
  grid-row: 1;
}

.sr-sync__item--ok      .sr-sync__status-dot { background: #52a852; box-shadow: 0 0 6px #52a852aa; }
.sr-sync__item--error   .sr-sync__status-dot { background: #e05252; box-shadow: 0 0 6px #e05252aa; }
.sr-sync__item--pending .sr-sync__status-dot { background: #e0c252; box-shadow: 0 0 6px #e0c252aa; }
.sr-sync__item--never   .sr-sync__status-dot { background: #444; }

/* Pfade */
.sr-sync__paths {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  grid-row: 1;
}

.sr-sync__path-row {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
}

.sr-sync__path-badge {
  flex-shrink: 0;
  padding: 1px 6px;
  border-radius: 3px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.5px;
}

.sr-sync__path-badge--nc { background: #1a3a6a; color: #7eaecf; }
.sr-sync__path-badge--lr { background: #3a1a6a; color: #ae7ecf; }

.sr-sync__path {
  font-size: 12px;
  color: #aaa;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-family: 'Fira Code', 'Consolas', monospace;
}

.sr-sync__arrow {
  padding-left: 30px;
  color: #444;
}

.sr-sync__arrow svg { width: 40px; height: 10px; }

/* Meta */
.sr-sync__meta {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
  font-size: 11px;
  grid-row: 1;
  flex-shrink: 0;
}

.sr-sync__direction { color: #666; }
.sr-sync__last-sync { color: #555; white-space: nowrap; }

/* Aktionen */
.sr-sync__actions {
  display: flex;
  align-items: center;
  gap: 5px;
  grid-row: 1;
  flex-shrink: 0;
}

.sr-sync__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  padding: 5px 10px;
  border: 1px solid #2a2a4a;
  border-radius: 5px;
  background: transparent;
  color: #888;
  font-size: 12px;
  font-family: inherit;
  cursor: pointer;
  transition: background 150ms, color 150ms, border-color 150ms;
}

.sr-sync__btn svg { width: 14px; height: 14px; flex-shrink: 0; }
.sr-sync__btn:hover { background: #2a2a4a; color: #ddd; border-color: #3a3a5a; }
.sr-sync__btn:disabled { opacity: 0.5; cursor: not-allowed; }
.sr-sync__btn--active { background: #1a2a3a; border-color: #2a4a6a; color: #7eaecf; }

.sr-sync__btn--sync {
  border-color: #e94560;
  color: #e94560;
  min-width: 70px;
}
.sr-sync__btn--sync:hover:not(:disabled) {
  background: #e94560;
  color: #fff;
}

.sr-sync__btn--delete:hover { border-color: #e05252; color: #e05252; }

/* Spinning-Animation */
.spinning {
  animation: spin 1s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Log ──────────────────────────────────────────────────────────────────── */
.sr-sync__log {
  grid-column: 1 / -1;
  background: #0d0d1a;
  border-radius: 5px;
  padding: 10px 12px;
  font-family: 'Fira Code', 'Consolas', monospace;
  font-size: 11px;
  overflow-x: auto;
}

.sr-sync__log-title {
  font-size: 10px;
  color: #555;
  margin-bottom: 6px;
  font-family: inherit;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.sr-sync__log-entry {
  padding: 2px 0;
  border-bottom: 1px solid #1a1a2e;
  white-space: nowrap;
}

.sr-sync__log-entry--info  { color: #7ecf7e; }
.sr-sync__log-entry--error { color: #e05252; }

.log-expand-enter-active,
.log-expand-leave-active {
  transition: max-height 250ms ease, opacity 200ms ease;
  overflow: hidden;
  max-height: 300px;
}
.log-expand-enter-from,
.log-expand-leave-to {
  max-height: 0;
  opacity: 0;
}

/* ── Dialog ───────────────────────────────────────────────────────────────── */
.sr-dialog-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 2000;
  backdrop-filter: blur(4px);
}

.sr-dialog {
  background: #1e1e2e;
  border: 1px solid #2a2a4a;
  border-radius: 10px;
  width: 520px;
  max-width: calc(100vw - 40px);
  box-shadow: 0 20px 60px rgba(0,0,0,0.6);
}

.sr-dialog__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid #2a2a4a;
}

.sr-dialog__header h3 { margin: 0; font-size: 15px; color: #ddd; font-weight: 600; }

.sr-dialog__close {
  background: transparent;
  border: none;
  color: #666;
  font-size: 16px;
  cursor: pointer;
  line-height: 1;
  padding: 4px;
  transition: color 150ms;
}
.sr-dialog__close:hover { color: #e94560; }

.sr-dialog__body {
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.sr-dialog__field {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.sr-dialog__label {
  font-size: 12px;
  font-weight: 600;
  color: #aaa;
  text-transform: uppercase;
  letter-spacing: 0.4px;
}

.sr-dialog__input {
  padding: 9px 12px;
  background: #12122a;
  border: 1px solid #2a2a4a;
  border-radius: 6px;
  color: #e0e0e0;
  font-size: 13px;
  font-family: 'Fira Code', 'Consolas', monospace;
  outline: none;
  transition: border-color 150ms;
  width: 100%;
  box-sizing: border-box;
}

.sr-dialog__input:focus { border-color: #e94560; }
.sr-dialog__hint { font-size: 11px; color: #555; }

/* Radio-Gruppe */
.sr-dialog__radio-group {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.sr-dialog__radio {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 7px 12px;
  border: 1px solid #2a2a4a;
  border-radius: 6px;
  cursor: pointer;
  font-size: 13px;
  color: #888;
  transition: border-color 150ms, color 150ms, background 150ms;
  user-select: none;
}

.sr-dialog__radio:hover { border-color: #3a3a5a; color: #ccc; }

.sr-dialog__radio--active {
  border-color: #e94560;
  color: #e0e0e0;
  background: rgba(233, 69, 96, 0.1);
}

.sr-dialog__radio-icon { font-size: 14px; }

.sr-visually-hidden {
  position: absolute;
  width: 1px; height: 1px;
  padding: 0; margin: -1px;
  overflow: hidden;
  clip: rect(0,0,0,0);
  white-space: nowrap;
  border: 0;
}

.sr-dialog__footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding: 14px 20px;
  border-top: 1px solid #2a2a4a;
}

.sr-dialog__btn {
  padding: 8px 18px;
  border-radius: 6px;
  font-size: 13px;
  font-family: inherit;
  font-weight: 500;
  cursor: pointer;
  transition: background 150ms;
}

.sr-dialog__btn--cancel {
  background: transparent;
  border: 1px solid #2a2a4a;
  color: #888;
}
.sr-dialog__btn--cancel:hover { background: #2a2a4a; color: #ddd; }

.sr-dialog__btn--save {
  background: #e94560;
  border: 1px solid #e94560;
  color: #fff;
}
.sr-dialog__btn--save:hover:not(:disabled) { background: #c73550; }
.sr-dialog__btn--save:disabled { opacity: 0.5; cursor: not-allowed; }

/* Dialog-Transition */
.dialog-enter-active,
.dialog-leave-active {
  transition: opacity 200ms ease;
}
.dialog-enter-active .sr-dialog,
.dialog-leave-active .sr-dialog {
  transition: transform 200ms cubic-bezier(0.34, 1.4, 0.64, 1);
}
.dialog-enter-from,
.dialog-leave-to {
  opacity: 0;
}
.dialog-enter-from .sr-dialog,
.dialog-leave-to .sr-dialog {
  transform: scale(0.93) translateY(-10px);
}
</style>
