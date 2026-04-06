/**
 * StarRate E2E – Loupe View: Zoom, Navigation, Keyboard
 */

import { login, openFolder } from './helpers'

describe('Loupe View', () => {
  beforeEach(() => {
    cy.clearLocalStorage()
    login()
  })

  it('öffnet und schließt Lupe per Doppelklick und Esc', () => {
    openFolder()
    cy.get('.sr-grid__item').first().dblclick()
    cy.get('.sr-loupe', { timeout: 5000 }).should('be.visible')

    // Esc → zurück zu Grid
    cy.get('body').type('{esc}')
    cy.get('.sr-grid').should('be.visible')
  })

  it('Zoom per + und Reset per Space', () => {
    openFolder()
    cy.get('.sr-grid__item').first().dblclick()
    cy.get('.sr-loupe', { timeout: 5000 }).should('be.visible')

    // Initial: Fit (translated label)
    cy.get('.sr-loupe__zoom-level').should('not.contain', '%')

    // + zoomt rein → zeigt Prozent
    cy.get('body').type('+')
    cy.get('.sr-loupe__zoom-level').should('contain', '%')

    // Space → Reset zu Fit
    cy.get('body').type(' ')
    cy.get('.sr-loupe__zoom-level').should('not.contain', '%')

    cy.get('body').type('{esc}')
  })

  it('navigiert mit Pfeiltasten durch Bilder', () => {
    openFolder()
    cy.get('.sr-grid__item').first().dblclick()
    cy.get('.sr-loupe', { timeout: 5000 }).should('be.visible')

    // Dateiname merken
    cy.get('.sr-loupe__filename').invoke('text').then(firstName => {
      // Pfeil rechts → nächstes Bild
      cy.get('body').type('{rightarrow}')
      cy.get('.sr-loupe__filename').should('not.have.text', firstName)

      // Pfeil links → zurück zum ersten
      cy.get('body').type('{leftarrow}')
      cy.get('.sr-loupe__filename').should('have.text', firstName)
    })

    cy.get('body').type('{esc}')
  })

  it('Rating in Lupe per Tastatur setzen', () => {
    openFolder()
    cy.get('.sr-grid__item').first().dblclick()
    cy.get('.sr-loupe', { timeout: 5000 }).should('be.visible')

    cy.get('body').type('5')
    cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')

    // Sterne in der Lupe-Footer prüfen
    cy.get('.sr-loupe__footer .sr-stars__star--filled').should('have.length', 5)

    cy.get('body').type('{esc}')
  })

  it('Esc bei gezoomtem Zustand → erst Fit, dann schließen', () => {
    openFolder()
    cy.get('.sr-grid__item').first().dblclick()
    cy.get('.sr-loupe', { timeout: 5000 }).should('be.visible')

    // Zoom rein via +
    cy.get('body').type('+')
    cy.get('.sr-loupe__zoom-level').should('contain', '%')

    // Esc → Fit (nicht schließen)
    cy.get('body').type('{esc}')
    cy.get('.sr-loupe__zoom-level').should('not.contain', '%')
    cy.get('.sr-loupe').should('be.visible')

    // Nochmal Esc → jetzt schließen
    cy.get('body').type('{esc}')
    cy.get('.sr-grid').should('be.visible')
  })
})
