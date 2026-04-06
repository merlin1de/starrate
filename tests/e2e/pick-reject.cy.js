/**
 * StarRate E2E – Pick/Reject Workflow
 *
 * Testet: Pick setzen, Reject setzen, Filter, Badge-Anzeige.
 * Voraussetzung: enable_pick_ui ist in den Settings aktiviert.
 */

import { NC_URL, NC_USER, NC_PASS, login, openFolder, focusImage } from './helpers'

describe('Pick/Reject', () => {
  before(() => {
    // Pick-UI aktivieren via Settings-API
    cy.session('pick-setup', () => {
      cy.visit(`${NC_URL}/login`)
      cy.get('#user').clear().type(NC_USER)
      cy.get('#password').clear().type(NC_PASS)
      cy.get('[type=submit]').click()
      cy.url().should('include', '/apps/')
    })
    cy.request({
      method: 'POST',
      url: `${NC_URL}/index.php/apps/starrate/api/settings`,
      body: { enable_pick_ui: true },
      headers: { 'Content-Type': 'application/json', 'OCS-APIREQUEST': 'true' },
      auth: { user: NC_USER, pass: NC_PASS },
    })
  })

  beforeEach(() => {
    cy.clearLocalStorage()
    login()
  })

  it('setzt Pick per Tastatur (P) und zeigt Badge', () => {
    openFolder()
    focusImage(0)

    // Eventuelle bestehende Pick/Reject-Markierung zurücksetzen
    cy.get('.sr-grid__item').first().then($item => {
      if ($item.find('.sr-grid__pick-badge').length > 0) {
        // Pick ist gesetzt → P drücken zum Entfernen
        cy.get('.sr-grid').first().trigger('keydown', { key: 'p', bubbles: true })
        cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')
        cy.get('.sr-toast--success').should('not.exist')
      } else if ($item.find('.sr-grid__reject-overlay').length > 0) {
        // Reject ist gesetzt → X drücken zum Entfernen
        cy.get('.sr-grid').first().trigger('keydown', { key: 'x', bubbles: true })
        cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')
        cy.get('.sr-toast--success').should('not.exist')
      }
    })

    // Jetzt Pick setzen
    cy.get('.sr-grid').first().trigger('keydown', { key: 'p', bubbles: true })
    cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')

    // Pick-Badge sichtbar auf dem fokussierten Bild
    cy.get('.sr-grid__item').first().find('.sr-grid__pick-badge').should('exist')
  })

  it('setzt Reject per Tastatur (X) und zeigt Overlay', () => {
    openFolder()
    focusImage(0)

    // Eventuelle bestehende Markierung zurücksetzen
    cy.get('.sr-grid__item').first().then($item => {
      if ($item.find('.sr-grid__pick-badge').length > 0) {
        cy.get('.sr-grid').first().trigger('keydown', { key: 'p', bubbles: true })
        cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')
        cy.get('.sr-toast--success').should('not.exist')
      } else if ($item.find('.sr-grid__reject-overlay').length > 0) {
        cy.get('.sr-grid').first().trigger('keydown', { key: 'x', bubbles: true })
        cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')
        cy.get('.sr-toast--success').should('not.exist')
      }
    })

    // Jetzt Reject setzen
    cy.get('.sr-grid').first().trigger('keydown', { key: 'x', bubbles: true })
    cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')

    // Reject-Overlay sichtbar auf dem fokussierten Bild
    cy.get('.sr-grid__item').first().find('.sr-grid__reject-overlay', { timeout: 5000 }).should('exist')
  })

  it('filtert nach Pick', () => {
    openFolder()

    // Erst ein Bild als Pick setzen (falls keins gesetzt)
    focusImage(0)
    cy.get('.sr-grid').first().trigger('keydown', { key: 'p', bubbles: true })
    cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')

    // Pick-Filter aktivieren
    cy.get('.sr-filterbar__pill--pick').click()

    // Nur gepickte Bilder sichtbar
    cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 10000 })
      .should('have.length.greaterThan', 0)
      .each($item => {
        cy.wrap($item).find('.sr-grid__pick-badge').should('exist')
      })

    cy.get('.sr-filterbar__reset:not(.sr-filterbar__reset--mobile)').click()
  })

  it('filtert nach Reject', () => {
    openFolder()

    // Erst ein Bild als Reject setzen
    focusImage(0)
    cy.get('.sr-grid').first().trigger('keydown', { key: 'x', bubbles: true })
    cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')

    // Reject-Filter aktivieren
    cy.get('.sr-filterbar__pill--reject').click()

    cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 10000 })
      .should('have.length.greaterThan', 0)
      .each($item => {
        cy.wrap($item).find('.sr-grid__reject-overlay').should('exist')
      })

    cy.get('.sr-filterbar__reset:not(.sr-filterbar__reset--mobile)').click()
  })

  it('entfernt Pick mit nochmaligem P (Toggle)', () => {
    openFolder()
    focusImage(0)

    // Pick setzen
    cy.get('.sr-grid').first().trigger('keydown', { key: 'p', bubbles: true })
    cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')
    cy.get('.sr-grid__item').first().find('.sr-grid__pick-badge').should('exist')

    // Warten bis Toast verschwindet, dann Pick entfernen
    cy.get('.sr-toast--success').should('not.exist')
    cy.get('.sr-grid').first().trigger('keydown', { key: 'p', bubbles: true })
    cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')

    // Badge muss weg sein (optimistisches Update)
    cy.get('.sr-grid__item').first().find('.sr-grid__pick-badge').should('not.exist')
  })
})
