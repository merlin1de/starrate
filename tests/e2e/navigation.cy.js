/**
 * StarRate E2E – Navigation: Subfolder, Breadcrumbs, Modus-Wechsel
 */

import { APP_URL, login, openFolder, TEST_FOLDER } from './helpers'

describe('Navigation', () => {
  beforeEach(() => {
    cy.clearLocalStorage()
    login()
  })

  it('Root-Ordner zeigt Bilder oder Unterordner', () => {
    cy.visit(`${APP_URL}/#/`)
    // Entweder Bilder oder Ordner-Buttons
    cy.get('.sr-app', { timeout: 10000 }).should('be.visible')
  })

  it('Breadcrumb-Navigation: Home → Ordner → Home', () => {
    openFolder()

    // Breadcrumb zeigt Pfad-Segmente
    cy.get('.sr-breadcrumb__seg').should('have.length.greaterThan', 1)

    // Home-Button klicken
    cy.get('.sr-breadcrumb__seg').first().click()
    cy.url().should('not.include', 'folder')
  })

  it('Modus-Wechsel: Grid → Loupe → Grid', () => {
    openFolder()

    // Initial: Grid
    cy.get('.sr-grid').should('be.visible')

    // Wechsel zu Loupe via Button
    cy.get('.sr-breadcrumb__mode-btn').eq(1).click()
    cy.get('.sr-loupe', { timeout: 5000 }).should('be.visible')

    // Zurück zu Grid
    cy.get('body').type('{esc}')
    cy.get('.sr-grid').should('be.visible')
  })

  it('Unterordner-Buttons navigieren in Subfolder', () => {
    // Diesen Test nur ausführen wenn Root Subfolder hat
    cy.visit(`${APP_URL}/#/`)
    cy.get('.sr-app', { timeout: 10000 }).should('be.visible')

    cy.get('body').then($body => {
      if ($body.find('.sr-folders__item').length > 0) {
        // Klick auf ersten Subfolder
        cy.get('.sr-folders__item').first().click()
        // URL sollte /folder/ enthalten
        cy.url().should('include', 'folder')
        // Breadcrumb sollte mindestens 2 Segmente haben
        cy.get('.sr-breadcrumb__seg').should('have.length.greaterThan', 1)
      } else {
        cy.log('Keine Subfolder im Root — Test übersprungen')
      }
    })
  })

  it('Direkter URL-Zugriff auf Ordner funktioniert', () => {
    cy.visit(`${APP_URL}/#/folder${TEST_FOLDER}`)
    cy.get('.sr-grid', { timeout: 10000 }).should('be.visible')
    cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 15000 })
      .should('have.length.greaterThan', 0)
  })
})
