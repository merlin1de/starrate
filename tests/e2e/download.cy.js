/**
 * StarRate E2E – Bild-Download (Einzel aus der Loupe + Auswahl als ZIP).
 */
import { login, openFolder, NC_URL, createShare, deleteShare } from './helpers'

describe('Image Download', () => {

  // ── Eingeloggt ──────────────────────────────────────────────────────────────
  describe('Logged-in', () => {
    beforeEach(() => login())

    it('Loupe zeigt den Download-Button (oben rechts)', () => {
      openFolder()
      cy.get('.sr-grid__item').first().dblclick()
      cy.get('.sr-loupe', { timeout: 5000 }).should('be.visible')
      cy.get('.sr-loupe__download').should('be.visible')
    })

    it('Toolbar zeigt "Download (N)" nur bei Auswahl, mit korrekter Anzahl', () => {
      openFolder()
      // Ohne Auswahl: kein Download-Button in der Toolbar
      cy.get('.sr-filterbar__action--download').should('not.exist')

      // Zwei Bilder selektieren
      cy.get('.sr-grid__item').eq(0).click({ ctrlKey: true })
      cy.get('.sr-grid__item').eq(2).click({ ctrlKey: true })
      cy.get('.sr-selbar', { timeout: 3000 }).should('be.visible')

      // Toolbar-Button erscheint mit Anzahl 2
      cy.get('.sr-filterbar__action--download').should('be.visible').and('contain', '2')

      // Auswahl aufheben → Button verschwindet
      cy.get('.sr-selbar__clear').click()
      cy.get('.sr-filterbar__action--download').should('not.exist')
    })

    it('Klick auf "Download (N)" löst /api/download-zip mit ids aus', () => {
      openFolder()
      cy.intercept('GET', '**/api/download-zip*').as('zip')

      cy.get('.sr-grid__item').eq(0).click({ ctrlKey: true })
      cy.get('.sr-grid__item').eq(1).click({ ctrlKey: true })
      cy.get('.sr-filterbar__action--download').click()

      cy.wait('@zip').its('request.url').should('include', 'ids=')
    })
  })

  // ── Guest-Share: Gating über allow_download ─────────────────────────────────
  describe('Guest ohne allow_download', () => {
    let token
    before(() => {
      login()
      createShare({ permissions: 'view', guest_name: 'NoDownload' }).then(s => { token = s.token })
    })
    after(() => deleteShare(token))

    it('zeigt KEINEN Download-Button in der Loupe', () => {
      cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)
      cy.get('.sr-grid__item', { timeout: 15000 }).first().dblclick()
      cy.get('.sr-loupe', { timeout: 5000 }).should('be.visible')
      cy.get('.sr-loupe__download').should('not.exist')
    })
  })

  describe('Guest mit allow_download', () => {
    let token
    before(() => {
      login()
      createShare({ permissions: 'view', guest_name: 'WithDownload', allow_download: true })
        .then(s => { token = s.token })
    })
    after(() => deleteShare(token))

    it('zeigt den Download-Button in der Loupe', () => {
      cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)
      cy.get('.sr-grid__item', { timeout: 15000 }).first().dblclick()
      cy.get('.sr-loupe', { timeout: 5000 }).should('be.visible')
      cy.get('.sr-loupe__download').should('be.visible')
    })
  })
})
