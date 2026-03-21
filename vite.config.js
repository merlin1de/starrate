import { createAppConfig } from '@nextcloud/vite-config'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

const __dirname = dirname(fileURLToPath(import.meta.url))

export default createAppConfig({
  main: 'src/main.js',
  guest: 'src/guest.js',
}, {
  config: {
    resolve: {
      alias: {
        '@': resolve(__dirname, 'src'),
      },
    },
  },
})
