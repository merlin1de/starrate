import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
  plugins: [vue()],
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./tests/js/setup.js'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'html'],
      reportsDirectory: './tests/results/coverage-js',
    },
    reporters: ['verbose', 'junit'],
    outputFile: {
      junit: './tests/results/junit-js.xml',
    },
  },
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
      // Nextcloud-Module mocken
      '@nextcloud/l10n':   resolve(__dirname, 'tests/js/mocks/nextcloud-l10n.js'),
      '@nextcloud/axios':  resolve(__dirname, 'tests/js/mocks/nextcloud-axios.js'),
      '@nextcloud/router': resolve(__dirname, 'tests/js/mocks/nextcloud-router.js'),
    },
  },
})
