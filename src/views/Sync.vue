<template>
  <div class="sr-sync-view">
    <SyncPanel @toast="showToast" />

    <Teleport to="body">
      <div class="sr-toasts">
        <TransitionGroup name="toast">
          <div
            v-for="toast in toasts"
            :key="toast.id"
            class="sr-toast"
            :class="`sr-toast--${toast.type}`"
          >{{ toast.message }}</div>
        </TransitionGroup>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import SyncPanel from '../components/SyncPanel.vue'

const toasts   = ref([])
let   toastCounter = 0

function showToast(message, type = 'success') {
  const id = ++toastCounter
  toasts.value.push({ id, message, type })
  setTimeout(() => { toasts.value = toasts.value.filter(t => t.id !== id) }, 3500)
}
</script>

<style scoped>
.sr-sync-view {
  background: #1a1a2e;
  min-height: 100%;
  font-family: 'Inter', system-ui, -apple-system, sans-serif;
}

.sr-toasts {
  position: fixed;
  bottom: 24px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  flex-direction: column;
  gap: 8px;
  z-index: 9999;
  pointer-events: none;
}

.sr-toast {
  padding: 10px 20px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 500;
  white-space: nowrap;
  box-shadow: 0 4px 12px rgba(0,0,0,0.4);
}

.sr-toast--success { background: #2a4a2a; color: #7ecf7e; border: 1px solid #3a6a3a; }
.sr-toast--error   { background: #4a1a1a; color: #e94560; border: 1px solid #6a2a2a; }
.sr-toast--warning { background: #3a2a1a; color: #e0c252; border: 1px solid #5a4a2a; }

.toast-enter-active, .toast-leave-active { transition: all 250ms ease; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateY(12px); }
</style>
