/**
 * StarRate E2E – Kommentare: Owner + Gast
 */

import { NC_URL, NC_USER, NC_PASS, APP_URL, TEST_FOLDER, login, openFolder, createShare, deleteShare } from './helpers'

describe('Kommentare', () => {

  // ── Hilfsfunktion: Settings-API aufrufen ─────────────────────────────────

  function setCommentsEnabled(enabled) {
    cy.request({
      method: 'POST',
      url: `${NC_URL}/index.php/apps/starrate/api/settings`,
      body: { comments_enabled: enabled },
      headers: { 'Content-Type': 'application/json', 'OCS-APIREQUEST': 'true' },
      auth: { user: NC_USER, pass: NC_PASS },
    })
  }

  function deleteCommentApi(fileId) {
    cy.request({
      method: 'DELETE',
      url: `${NC_URL}/index.php/apps/starrate/api/rating/${fileId}/comment`,
      headers: { 'OCS-APIREQUEST': 'true' },
      auth: { user: NC_USER, pass: NC_PASS },
      failOnStatusCode: false,
    })
  }

  // ── Owner-Kommentare ────────────────────────────────────────────────────

  describe('Owner', () => {

    before(() => {
      login()
      setCommentsEnabled(true)
    })

    it('zeigt Kommentar-Button in der Loupe', () => {
      login()
      openFolder()
      // Erstes Bild in die Loupe öffnen
      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)').first().dblclick()
      cy.get('.sr-loupe', { timeout: 5000 }).should('be.visible')
      cy.get('.sr-loupe__comment-btn').should('be.visible')
    })

    it('erstellt, bearbeitet und löscht einen Kommentar', () => {
      login()
      openFolder()
      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)').first().dblclick()
      cy.get('.sr-loupe', { timeout: 5000 }).should('be.visible')

      // Neuen Kommentar erstellen
      cy.get('.sr-loupe__comment-btn').click()
      cy.get('.sr-loupe__comment-textarea', { timeout: 3000 }).should('be.visible')
      cy.get('.sr-loupe__comment-textarea').type('E2E Testkommentar')
      cy.get('.sr-loupe__comment-btn-save').click()

      // View-Modus: Kommentar sichtbar
      cy.get('.sr-loupe__comment-text', { timeout: 5000 }).should('contain', 'E2E Testkommentar')
      cy.get('.sr-loupe__comment-btn--active').should('exist')

      // Bearbeiten
      cy.get('.sr-loupe__comment-action').first().click() // Edit-Button
      cy.get('.sr-loupe__comment-textarea').clear().type('Bearbeitet')
      cy.get('.sr-loupe__comment-btn-save').click()
      cy.get('.sr-loupe__comment-text', { timeout: 5000 }).should('contain', 'Bearbeitet')

      // Löschen
      cy.get('.sr-loupe__comment-action--delete').click()
      cy.get('.sr-loupe__comment-btn-save--danger').click()

      // Sheet geschlossen, Button nicht mehr aktiv
      cy.get('.sr-loupe__comment-sheet-overlay--open').should('not.exist')
      cy.get('.sr-loupe__comment-btn--active').should('not.exist')
    })

    it('versteckt Kommentar-Button wenn comments_enabled=false', () => {
      login()
      setCommentsEnabled(false)
      openFolder()
      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)').first().dblclick()
      cy.get('.sr-loupe', { timeout: 5000 }).should('be.visible')
      cy.get('.sr-loupe__comment-btn').should('not.exist')

      // Cleanup: wieder aktivieren
      setCommentsEnabled(true)
    })
  })

  // ── Gast-Kommentare ─────────────────────────────────────────────────────

  describe('Gast', () => {
    let token

    before(() => {
      login()
      setCommentsEnabled(true)
      createShare({
        permissions: 'rate',
        guest_name: 'Kommentar-Gast',
        allow_comment: true,
      }).then(share => {
        token = share.token
      })
    })

    after(() => deleteShare(token))

    it('Gast sieht Kommentar-Button und kann kommentieren', () => {
      cy.clearCookies()
      cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)
      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 15000 })
        .should('have.length.greaterThan', 0)

      // Erstes Bild in Loupe öffnen
      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)').first().dblclick()
      cy.get('.sr-loupe', { timeout: 5000 }).should('be.visible')
      cy.get('.sr-loupe__comment-btn').should('be.visible')

      // Kommentar schreiben
      cy.get('.sr-loupe__comment-btn').click()
      cy.get('.sr-loupe__comment-textarea', { timeout: 3000 }).should('be.visible')
      cy.get('.sr-loupe__comment-textarea').type('Gast-Kommentar E2E')
      cy.get('.sr-loupe__comment-btn-save').click()
      cy.get('.sr-loupe__comment-text', { timeout: 5000 }).should('contain', 'Gast-Kommentar E2E')
      cy.get('.sr-loupe__comment-meta').should('contain', 'Kommentar-Gast')
    })

    it('Gast ohne allow_comment sieht keinen Button', () => {
      login()
      createShare({
        permissions: 'rate',
        guest_name: 'Kein-Kommentar',
        allow_comment: false,
      }).then(share => {
        cy.clearCookies()
        cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${share.token}`)
        cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 15000 })
          .should('have.length.greaterThan', 0)
        cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)').first().dblclick()
        cy.get('.sr-loupe', { timeout: 5000 }).should('be.visible')
        cy.get('.sr-loupe__comment-btn').should('not.exist')
        deleteShare(share.token)
      })
    })
  })
})
