import { createApp } from 'vue'
import SettingsPanel from './views/SettingsPanel.vue'

const el = document.getElementById('starrate-settings')
if (el) {
  const initial = JSON.parse(el.dataset.settings || '{}')
  createApp(SettingsPanel, { initial }).mount(el)
}
