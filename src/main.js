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
  // 1. localStorage – von files-context.js beim Klick auf StarRate gesetzt.
  //    Hat Vorrang vor dem Hash: Chrome cached den alten Hash und würde sonst
  //    den falschen Ordner öffnen, obwohl der Nutzer gerade aus NC Files kommt.
  //    Einmal-Token: nach dem Lesen sofort löschen, damit Browser-Back danach
  //    korrekt den Hash (= zuletzt besuchten Ordner) nutzt.
  try {
    const raw = localStorage.getItem('starrate_nc_path')
    if (raw) {
      const { dir, t } = JSON.parse(raw)
      if (dir && dir !== '/' && (Date.now() - t) < 5 * 60_000) {
        localStorage.removeItem('starrate_nc_path')
        return dir
      }
    }
  } catch {}

  // 2. Bereits ein Pfad im Hash? (Browser-Back, Bookmark) → nichts tun
  if (window.location.hash && window.location.hash !== '#/' && window.location.hash !== '#') {
    return null
  }

  // 3. Referrer (NC setzt no-referrer — greift in der Praxis nicht)
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
