import '../css/starrate.css'
import { createApp } from 'vue'
import { createRouter, createWebHashHistory } from 'vue-router'
import App from './views/Gallery.vue'
import Sync from './views/Sync.vue'

const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: '/', component: App },
    { path: '/folder/:path(.*)', component: App },
    { path: '/sync', component: Sync },
  ],
})

function resolveInitialPath() {
  // 1. Bereits ein Pfad in der URL? → nichts tun
  if (window.location.hash && window.location.hash !== '#/' && window.location.hash !== '#') {
    return null
  }
  // 2. Kommen wir aus NC Files? → ?dir=/Fotos/E2E auslesen
  try {
    const ref = new URL(document.referrer)
    if (ref.pathname.includes('/apps/files')) {
      const dir = ref.searchParams.get('dir')
      if (dir && dir !== '/') return dir
    }
  } catch {}
  return null
}

const app = createApp(App)
app.use(router)
router.isReady().then(async () => {
  const path = resolveInitialPath()
  if (path) await router.replace(`/folder${path}`)
  app.mount('#starrate-root')
})
