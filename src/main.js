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

const app = createApp(App)
app.use(router)
router.isReady().then(() => app.mount('#starrate-root'))
