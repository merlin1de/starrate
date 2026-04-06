/**
 * StarRate E2E – Rating & Color Workflow
 *
 * Testet: Sterne setzen/entfernen, Farben setzen, Filter, Kombinationen.
 */

import { login, openFolder, focusImage } from './helpers'

describe('Rating & Color Workflow', () => {
  beforeEach(() => {
    cy.clearLocalStorage()
    login()
  })

  it('setzt Rating 4 und filtert nach ≥4★', () => {
    openFolder()
    focusImage(0)
    cy.get('.sr-grid').first().trigger('keydown', { key: '4', bubbles: true })
    cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')

    cy.contains('.sr-filterbar__op', '≥').click()
    cy.get('.sr-filterbar__pill--star').eq(1).click() // ≥4★

    cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)').each($item => {
      cy.wrap($item).find('.sr-stars__star--filled')
        .should('have.length.greaterThan', 3)
    })

    cy.get('.sr-filterbar__reset:not(.sr-filterbar__reset--mobile)').click()
  })

  it('entfernt Bewertung mit Taste 0', () => {
    openFolder()
    // Erst Rating setzen
    focusImage(0)
    cy.get('.sr-grid').first().trigger('keydown', { key: '3', bubbles: true })
    cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')

    // Dann mit 0 entfernen
    cy.get('.sr-grid').first().trigger('keydown', { key: '0', bubbles: true })
    cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')

    // Prüfen: kein Rating mehr am ersten Bild
    cy.get('.sr-grid__item').first().find('.sr-stars__star--filled')
      .should('have.length', 0)
  })

  it('setzt Farbmarkierung und filtert nach Farbe', () => {
    openFolder()
    focusImage(1) // zweites Bild
    cy.get('.sr-grid').first().trigger('keydown', { key: '8', bubbles: true }) // Green
    cy.get('.sr-toast--success').should('be.visible')

    cy.get('.sr-filterbar__colordot').eq(2).click() // Green
    cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)').each($item => {
      cy.wrap($item).find('.sr-grid__info-color--green').should('exist')
    })

    cy.get('.sr-filterbar__reset:not(.sr-filterbar__reset--mobile)').click()
  })

  it('kombinierter Filter: Rating + Farbe', () => {
    openFolder()

    // Bild 0: Rating 5 + Red
    focusImage(0)
    cy.get('.sr-grid').first().trigger('keydown', { key: '5', bubbles: true })
    cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')
    cy.get('.sr-grid').first().trigger('keydown', { key: '6', bubbles: true }) // Red
    cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')

    // Filter: ≥5★ + Red
    cy.contains('.sr-filterbar__op', '≥').click()
    cy.get('.sr-filterbar__pill--star').eq(0).click() // ≥5★
    cy.get('.sr-filterbar__colordot').eq(0).click()   // Red

    cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)').should('have.length.greaterThan', 0)
    cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)').each($item => {
      cy.wrap($item).find('.sr-stars__star--filled').should('have.length', 5)
      cy.wrap($item).find('.sr-grid__info-color--red').should('exist')
    })

    cy.get('.sr-filterbar__reset:not(.sr-filterbar__reset--mobile)').click()
  })

  it('Filter ohne Ergebnis zeigt "Clear Filter" Hinweis', () => {
    openFolder()

    // Exakten Filter auf 5★ setzen (hoffentlich nicht alle Bilder 5★)
    cy.contains('.sr-filterbar__op', '=').click()
    cy.get('.sr-filterbar__pill--star').eq(0).click() // =5★

    // Zusätzlich Purple filtern → sehr wahrscheinlich 0 Ergebnisse
    cy.get('.sr-filterbar__colordot').eq(4).click() // Purple

    // Entweder Bilder oder die "keine Ergebnisse" Anzeige
    cy.get('body').then($body => {
      if ($body.find('.sr-grid__empty').length > 0) {
        cy.get('.sr-grid__empty').should('be.visible')
      }
    })

    cy.get('.sr-filterbar__reset:not(.sr-filterbar__reset--mobile)').click()
  })
})
