<template>
  <div class="sr-folder-popover">
    <button
      class="sr-folder-popover__trigger"
      type="button"
      :title="t('starrate', 'Unterordner')"
      :aria-expanded="open"
      aria-haspopup="menu"
      @click="toggle"
    >
      <span aria-hidden="true">📁</span>
      {{ n('starrate', '%n Ordner', '%n Ordner', folders.length) }}
      <span class="sr-folder-popover__caret" aria-hidden="true">▾</span>
    </button>

    <Teleport v-if="open" to="body">
      <div
        class="sr-folder-popover__catcher"
        @click="close"
        @wheel="close"
        @touchmove="close"
      ></div>
      <div
        ref="menuRef"
        class="sr-folder-popover__menu"
        :style="menuStyle"
        role="menu"
      >
        <button
          v-for="(f, i) in folders"
          :key="f.path"
          ref="itemRefs"
          class="sr-folder-popover__item"
          type="button"
          role="menuitem"
          :tabindex="i === 0 ? 0 : -1"
          @click="select(f)"
          @keydown.down.prevent="focusItem(i + 1)"
          @keydown.up.prevent="focusItem(i - 1)"
        >
          <span class="sr-folder-popover__icon" aria-hidden="true">📁</span>
          <span class="sr-folder-popover__name">{{ f.name }}</span>
        </button>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, nextTick, onUnmounted } from 'vue'
import { t, n } from '@nextcloud/l10n'

defineProps({
  folders: {
    type: Array,
    required: true,
  },
})

const emit = defineEmits(['navigate'])

const open      = ref(false)
const menuStyle = ref({})
const menuRef   = ref(null)
const itemRefs  = ref([])

// Position des Triggers merken, damit wir Menüposition berechnen können
let triggerEl = null

function toggle(e) {
  triggerEl = e.currentTarget
  if (open.value) {
    close()
  } else {
    openMenu()
  }
}

async function openMenu() {
  computePosition()
  open.value = true
  await nextTick()
  // Nach dem Render: Menü-Höhe neu messen und ggf. Flip nach oben
  recomputePosition()
  // Escape-Listener global (Teleport landet in body, kein natives Keydown sonst)
  document.addEventListener('keydown', onDocKeydown, true)
  // Focus erstes Item
  itemRefs.value[0]?.focus()
}

function close() {
  if (!open.value) return
  open.value = false
  document.removeEventListener('keydown', onDocKeydown, true)
  triggerEl?.focus?.()
}

function onDocKeydown(e) {
  if (e.key === 'Escape') {
    e.stopPropagation()
    close()
  }
}

function computePosition() {
  if (!triggerEl) return
  const rect = triggerEl.getBoundingClientRect()
  // Menü immer rechtsbündig am Trigger ausrichten — so wandert es nicht mysteriös herum
  const rightFromViewport = Math.max(8, window.innerWidth - rect.right)
  menuStyle.value = {
    position: 'fixed',
    top:   `${rect.bottom + 4}px`,
    right: `${rightFromViewport}px`,
    minWidth: '200px',
    maxWidth: `${window.innerWidth - 16}px`,
  }
}

function recomputePosition() {
  if (!triggerEl || !menuRef.value) return
  const rect = triggerEl.getBoundingClientRect()
  const menuH = menuRef.value.offsetHeight
  const viewportH = window.innerHeight
  // Flip nach oben, wenn unten kein Platz
  const top = (rect.bottom + 4 + menuH > viewportH && rect.top - 4 - menuH > 0)
    ? rect.top - 4 - menuH
    : rect.bottom + 4
  menuStyle.value = {
    ...menuStyle.value,
    top:  `${top}px`,
  }
}

function select(folder) {
  emit('navigate', folder.path)
  close()
}

function focusItem(idx) {
  const items = itemRefs.value
  if (!items.length) return
  const n = (idx + items.length) % items.length
  items[n]?.focus()
}

onUnmounted(() => {
  document.removeEventListener('keydown', onDocKeydown, true)
})

defineExpose({ close })
</script>

<style scoped>
.sr-folder-popover {
  display: inline-flex;
  align-items: center;
  flex-shrink: 0;
}

.sr-folder-popover__trigger {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #1a1a2e;
  border: 1px solid #2a2a4a;
  border-radius: 4px;
  color: #a1a1aa;
  cursor: pointer;
  font-size: 11px;
  padding: 2px 8px;
  white-space: nowrap;
  flex-shrink: 0;
  margin-left: 6px;
  font-family: inherit;
  transition: color 0.15s, border-color 0.15s;
  box-shadow: none !important;
  appearance: none !important;
  -webkit-appearance: none !important;
}

.sr-folder-popover__trigger:hover {
  color: #d4d4d8;
  border-color: #5a5a8a;
}

.sr-folder-popover__trigger:focus,
.sr-folder-popover__trigger:focus-visible,
.sr-folder-popover__trigger:active {
  outline: none !important;
  box-shadow: none !important;
}

.sr-folder-popover__caret {
  font-size: 9px;
  opacity: 0.7;
}

/* Desktop: Popover-Trigger ausblenden — Kind-Ordner sind als Pills (sr-folders) sichtbar */
@media (pointer: fine) {
  .sr-folder-popover { display: none; }
}
</style>

<style>
/* Teleport-Children (body) können nicht scoped werden */
.sr-folder-popover__catcher {
  position: fixed;
  inset: 0;
  background: transparent;
  z-index: 8000;
}

.sr-folder-popover__menu {
  z-index: 8001;
  background: #16213e;
  border: 1px solid #2a2a4a;
  border-radius: 6px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
  padding: 4px 0;
  max-height: 60vh;
  overflow-y: auto;
  scrollbar-width: thin;
  scrollbar-color: #2a2a4a transparent;
  display: flex;
  flex-direction: column;
}

.sr-folder-popover__menu::-webkit-scrollbar { width: 6px; }
.sr-folder-popover__menu::-webkit-scrollbar-thumb { background: #2a2a4a; border-radius: 3px; }

.sr-folder-popover__item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  background: transparent;
  border: none;
  color: #d4d4d8;
  font-size: 13px;
  font-family: inherit;
  cursor: pointer;
  text-align: left;
  white-space: nowrap;
  min-height: 36px;
  box-shadow: none !important;
  appearance: none !important;
  -webkit-appearance: none !important;
}

.sr-folder-popover__item:hover,
.sr-folder-popover__item:focus,
.sr-folder-popover__item:focus-visible {
  background: #1f2d4e;
  outline: none !important;
  box-shadow: none !important;
  color: #fff;
}

.sr-folder-popover__icon {
  font-size: 14px;
  flex-shrink: 0;
}

.sr-folder-popover__name {
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 260px;
}
</style>
