/**
 * StarRate – Guest Gallery entry point
 *
 * Loaded by templates/guest.php (standalone, no Nextcloud layout).
 * Reads data-* attributes from #sr-guest-app and mounts GuestGallery.
 */

import { createApp } from 'vue'
import GuestGallery from './views/GuestGallery.vue'

const el = document.getElementById('sr-guest-app')

if (el) {
  const token     = el.dataset.token     ?? ''
  const canRate   = el.dataset.canRate   === 'true'
  const minRating = parseInt(el.dataset.minRating ?? '0', 10)

  createApp(GuestGallery, { token, canRate, minRating }).mount(el)
}
