/**
 * StarRate E2E – Batch Selection & Rating
 */

import { login, openFolder } from './helpers'

describe('Batch Selection', () => {
  beforeEach(() => {
    cy.clearLocalStorage()
    login()
  })

  it('Shift+Klick selektiert Bereich, bewertet alle, Esc hebt auf', () => {
    openFolder()

    cy.get('.sr-grid__item').first().click()
    cy.get('.sr-grid__item').eq(3).click({ shiftKey: true })

    cy.get('.sr-selbar', { timeout: 3000 }).should('be.visible')
    cy.get('.sr-selbar__count').should('contain', '4')

    // Rating 3 setzen
    cy.get('.sr-selbar__btn--star').eq(3).click()
    cy.get('.sr-toast--success', { timeout: 5000 }).should('contain', '4')

    cy.get('.sr-selbar').should('be.visible')
    cy.get('body').type('{esc}')
    cy.get('.sr-selbar').should('not.exist')
  })

  it('Ctrl+Klick selektiert einzelne, nicht-zusammenhängende Bilder', () => {
    openFolder()

    // Bild 0 und Bild 2 selektieren (nicht 1) — beide Ctrl+Klick
    cy.get('.sr-grid__item').eq(0).click({ ctrlKey: true })
    cy.get('.sr-grid__item').eq(2).click({ ctrlKey: true })

    cy.get('.sr-selbar', { timeout: 3000 }).should('be.visible')
    cy.get('.sr-selbar__count').should('contain', '2')

    cy.get('body').type('{esc}')
  })

  it('Batch-Farbe setzen auf mehrere Bilder', () => {
    openFolder()

    cy.get('.sr-grid__item').eq(0).click()
    cy.get('.sr-grid__item').eq(2).click({ shiftKey: true }) // 0,1,2

    cy.get('.sr-selbar', { timeout: 3000 }).should('be.visible')

    // Batch-Farbe Yellow setzen (Color-Button in SelectionBar)
    cy.get('.sr-selbar__btn--color').eq(1).click() // Yellow
    cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')

    cy.get('body').type('{esc}')
  })
})
