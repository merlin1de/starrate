module.exports = {
  e2e: {
    baseUrl: process.env.CYPRESS_NC_URL || process.env.NC_URL || 'https://cloud.mischler.info',

    specPattern:        'tests/e2e/**/*.cy.js',
    supportFile:        false,
    screenshotsFolder:  'tests/results/cypress/screenshots',
    videosFolder:       'tests/results/cypress/videos',
    downloadsFolder:    'tests/results/cypress/downloads',

    viewportWidth:  1440,
    viewportHeight: 900,

    defaultCommandTimeout: 10000,
    pageLoadTimeout:       30000,
    requestTimeout:        15000,

    chromeWebSecurity: false,

    env: {
      NC_URL:    process.env.CYPRESS_NC_URL    || process.env.NC_URL    || 'https://cloud.mischler.info',
      NC_USER:   process.env.CYPRESS_NC_USER   || process.env.NC_USER   || 'test',
      NC_PASS:   process.env.CYPRESS_NC_PASS   || process.env.NC_PASS   || 'test',
      NC_USER_B: process.env.CYPRESS_NC_USER_B || process.env.NC_USER_B || 'test2',
      NC_PASS_B: process.env.CYPRESS_NC_USER_B || process.env.NC_PASS_B || 'test2',
      // Opt-in: Multi-User-Tests nur ausführen wenn explizit gesetzt
      NC_MULTI_USER: process.env.CYPRESS_NC_MULTI_USER || process.env.NC_MULTI_USER || '',
    },
  },
}
