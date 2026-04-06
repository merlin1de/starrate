/**
 * StarRate E2E – Shared helpers and constants.
 */

export const NC_URL  = Cypress.env('NC_URL')  || 'http://localhost:8080'
export const NC_USER = Cypress.env('NC_USER') || 'admin'
export const NC_PASS = Cypress.env('NC_PASS') || 'admin'
export const APP_URL = `${NC_URL}/apps/starrate`
export const TEST_FOLDER = '/Fotos/E2E-Test'

// ResizeObserver-Fehler ignorieren (harmloser Browser-Bug)
Cypress.on('uncaught:exception', (err) => {
  if (err.message.includes('ResizeObserver loop')) return false
})

/**
 * Login via cy.session() (cached ab dem 2. Aufruf).
 */
export function login(user = NC_USER, pass = NC_PASS) {
  cy.session([user, pass], () => {
    cy.visit(`${NC_URL}/login`)
    cy.get('#user').clear().type(user)
    cy.get('#password').clear().type(pass)
    cy.get('[type=submit]').click()
    cy.url().should('include', '/apps/')
  })
}

/**
 * Öffnet einen StarRate-Ordner und wartet bis Bilder geladen sind.
 */
export function openFolder(path = TEST_FOLDER) {
  cy.visit(`${APP_URL}/#/folder${path}`)
  cy.get('.sr-grid', { timeout: 10000 }).should('be.visible')
  cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 15000 })
    .should('have.length.greaterThan', 0)
}

/**
 * Fokussiert das n-te Bild im Grid (0-basiert) per ArrowRight.
 * Erstes ArrowRight setzt Focus auf 0 UND bewegt auf 1 (Grid initialisiert focusedIndex
 * bei -1 → 0 und addiert dann +1). Daher: Home-Taste für Reset auf 0, dann ArrowRight.
 */
export function focusImage(index) {
  // Home setzt Focus auf Index 0
  cy.get('.sr-grid').first().trigger('keydown', { key: 'Home', bubbles: true })
  for (let i = 0; i < index; i++) {
    cy.get('.sr-grid').first().trigger('keydown', { key: 'ArrowRight', bubbles: true })
  }
}

/**
 * Erstellt einen Share via API und gibt das Token zurück.
 */
export function createShare(opts = {}) {
  const body = {
    nc_path: opts.nc_path || TEST_FOLDER,
    permissions: opts.permissions || 'rate',
    guest_name: opts.guest_name || 'E2E-Tester',
    ...opts,
  }
  return cy.request({
    method: 'POST',
    url: `${NC_URL}/index.php/apps/starrate/api/share`,
    body,
    headers: { 'Content-Type': 'application/json', 'OCS-APIREQUEST': 'true' },
    auth: { user: NC_USER, pass: NC_PASS },
  }).then(resp => resp.body.share)
}

/**
 * Löscht einen Share via API.
 */
export function deleteShare(token) {
  if (!token) return
  return cy.request({
    method: 'DELETE',
    url: `${NC_URL}/index.php/apps/starrate/api/share/${token}`,
    headers: { 'OCS-APIREQUEST': 'true' },
    auth: { user: NC_USER, pass: NC_PASS },
    failOnStatusCode: false,
  })
}
