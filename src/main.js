import '../css/starrate.css'
import { createApp } from 'vue'
import { createRouter, createWebHashHistory } from 'vue-router'
import App from './views/Gallery.vue'

const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: '/', component: App },
    { path: '/folder/:path(.*)', component: App },
  ],
})

function resolveInitialPath() {
  // 1. Bereits ein Pfad in der URL? → nichts tun
  if (window.location.hash && window.location.hash !== '#/' && window.location.hash !== '#') {
    return null
  }
  // 2. localStorage – von files-context.js gesetzt, 5 Min. gültig
  try {
    const raw = localStorage.getItem('starrate_nc_path')
    if (raw) {
      const { dir, t } = JSON.parse(raw)
      if (dir && dir !== '/' && (Date.now() - t) < 5 * 60_000) return dir
    }
  } catch {}

  // 3. Referrer (funktioniert wenn NC kein no-referrer setzt)
  try {
    const ref = new URL(document.referrer)
    if (ref.pathname.includes('/apps/files')) {
      const dir = ref.searchParams.get('dir')
      if (dir && dir !== '/') return dir
    }
  } catch {}
  return null
}

// viewport-fit=cover: ermöglicht env(safe-area-inset-bottom) für Android-Navigationsleiste
const viewportMeta = document.querySelector('meta[name="viewport"]')
if (viewportMeta && !viewportMeta.content.includes('viewport-fit')) {
  viewportMeta.content += ', viewport-fit=cover'
}

const app = createApp(App)
app.use(router)
router.isReady().then(async () => {
  const path = resolveInitialPath()
  if (path) await router.replace(`/folder${path}`)
  app.mount('#starrate-root')
})
