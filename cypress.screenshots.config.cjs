// Wegwerf-Config NUR für Marketing-Screenshots (nicht committen).
// Erzwingt ein großes Browser-Fenster via --window-size, sonst cappt headless
// Chrome/Electron bei 1280px und capture:'viewport' liefert winzige PNGs.
module.exports = {
  e2e: {
    baseUrl: process.env.CYPRESS_NC_URL || process.env.NC_URL || 'http://localhost',
    specPattern: ['tests/e2e/screenshots.cy.js'],
    supportFile: 'tests/e2e/support.js',
    screenshotsFolder: 'tests/results/cypress/screenshots',
    videosFolder: 'tests/results/cypress/videos',
    viewportWidth: 1840,
    viewportHeight: 1300,
    defaultCommandTimeout: 10000,
    pageLoadTimeout: 30000,
    chromeWebSecurity: false,
    setupNodeEvents(on) {
      on('before:browser:launch', (browser, launchOptions) => {
        if (browser.family === 'chromium' && browser.name !== 'electron') {
          launchOptions.args.push('--window-size=1856,1420')
          launchOptions.args.push('--force-device-scale-factor=1')
          launchOptions.args.push('--hide-scrollbars')
        }
        return launchOptions
      })
      return {}
    },
    env: {
      NC_URL:  process.env.CYPRESS_NC_URL  || process.env.NC_URL  || 'http://localhost',
      NC_USER: process.env.CYPRESS_NC_USER || process.env.NC_USER || 'admin',
      NC_PASS: process.env.CYPRESS_NC_PASS || process.env.NC_PASS || 'admin',
    },
  },
}
