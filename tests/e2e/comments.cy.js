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

  /** Löscht Kommentar vom ersten Bild im Testordner (Cleanup für idempotente Tests) */
  function cleanupFirstImageComment() {
    cy.request({
      url: `${NC_URL}/index.php/apps/starrate/api/images?path=${TEST_FOLDER}`,
      headers: { 'OCS-APIREQUEST': 'true' },
      auth: { user: NC_USER, pass: NC_PASS },
    }).then(resp => {
      const firstId = resp.body.images?.[0]?.id
      if (firstId) deleteCommentApi(firstId)
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
      cleanupFirstImageComment()
      openFolder()
      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)').first().dblclick()
      cy.get('.sr-loupe', { timeout: 5000 }).should('be.visible')
      cy.get('.sr-loupe__loading', { timeout: 10000 }).should('not.exist')

      // Neuen Kommentar erstellen
      cy.get('.sr-loupe__comment-btn').should('be.visible').click()
      cy.get('.sr-loupe__comment-textarea', { timeout: 10000 }).should('be.visible')
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
      cleanupFirstImageComment()
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
      cy.get('.sr-loupe__comment-textarea', { timeout: 10000 }).should('be.visible')
      cy.get('.sr-loupe__comment-textarea').type('Gast-Kommentar E2E')
      cy.get('.sr-loupe__comment-btn-save').click()
      cy.get('.sr-loupe__comment-text', { timeout: 5000 }).should('contain', 'Gast-Kommentar E2E')
      cy.get('.sr-loupe__comment-meta').should('contain', 'Kommentar-Gast')
    })

    it('Gast kann Kommentar bearbeiten und löschen', () => {
      cy.clearCookies()
      cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)
      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 15000 })
        .should('have.length.greaterThan', 0)

      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)').first().dblclick()
      cy.get('.sr-loupe', { timeout: 5000 }).should('be.visible')
      cy.get('.sr-loupe__loading', { timeout: 10000 }).should('not.exist')

      // Sheet öffnen (view-Modus, da Kommentar existiert)
      cy.get('.sr-loupe__comment-btn').should('be.visible').click()
      cy.get('.sr-loupe__comment-text', { timeout: 10000 }).should('be.visible')

      // Vorherigen Kommentar bearbeiten
      cy.get('.sr-loupe__comment-action').first().click()
      cy.get('.sr-loupe__comment-textarea').clear().type('Gast bearbeitet')
      cy.get('.sr-loupe__comment-btn-save').click()
      cy.get('.sr-loupe__comment-text', { timeout: 5000 }).should('contain', 'Gast bearbeitet')

      // Kommentar löschen
      cy.get('.sr-loupe__comment-action--delete').click()
      cy.get('.sr-loupe__comment-btn-save--danger').click()
      cy.get('.sr-loupe__comment-sheet-overlay--open').should('not.exist')
      cy.get('.sr-loupe__comment-btn--active').should('not.exist')
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

  // ── Passwortgeschützter Share: Kommentare ──────────────────────────────

  describe('Gast mit Passwort', () => {
    let token

    before(() => {
      login()
      setCommentsEnabled(true)
      cleanupFirstImageComment()
      createShare({
        permissions: 'rate',
        guest_name: 'PW-Kommentar-Gast',
        allow_comment: true,
        password: 'kommentar123',
      }).then(share => {
        token = share.token
      })
    })

    after(() => deleteShare(token))

    it('Passwort-Dialog erscheint vor Galerie', () => {
      cy.clearCookies()
      cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)
      cy.get('.sr-guest-pw__dialog', { timeout: 10000 }).should('be.visible')
      cy.get('.sr-grid').should('not.exist')
    })

    it('nach Login kann Gast kommentieren', () => {
      cy.clearCookies()
      cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)
      cy.get('.sr-guest-pw__dialog', { timeout: 10000 }).should('be.visible')

      // Passwort eingeben
      cy.get('.sr-guest-pw__input').type('kommentar123')
      cy.get('.sr-guest-pw__btn').click()

      // Galerie laden
      cy.get('.sr-grid', { timeout: 15000 }).should('be.visible')
      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 15000 })
        .should('have.length.greaterThan', 0)

      // Erstes Bild in Loupe öffnen
      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)').first().dblclick()
      cy.get('.sr-loupe', { timeout: 5000 }).should('be.visible')
      cy.get('.sr-loupe__comment-btn').should('be.visible')

      // Kommentar schreiben
      cy.get('.sr-loupe__comment-btn').click()
      cy.get('.sr-loupe__comment-textarea', { timeout: 10000 }).should('be.visible')
      cy.get('.sr-loupe__comment-textarea').type('PW-Gast Kommentar')
      cy.get('.sr-loupe__comment-btn-save').click()
      cy.get('.sr-loupe__comment-text', { timeout: 5000 }).should('contain', 'PW-Gast Kommentar')
      cy.get('.sr-loupe__comment-meta').should('contain', 'PW-Kommentar-Gast')
    })

    it('Kommentar-API ohne Passwort-Token → 401/403', () => {
      // Direkt API aufrufen ohne vorher verify durchzulaufen
      cy.request({
        method: 'POST',
        url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/comment`,
        body: { file_id: 99999, comment: 'Sneaky', guest_name: 'Hacker' },
        headers: { 'Content-Type': 'application/json' },
        failOnStatusCode: false,
      }).then(resp => {
        expect([401, 403]).to.include(resp.status)
      })
    })

    it('Kommentar-API mit falschem Passwort-Token → 401/403', () => {
      cy.request({
        method: 'POST',
        url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/comment`,
        body: { file_id: 99999, comment: 'Sneaky', guest_name: 'Hacker' },
        headers: {
          'Content-Type': 'application/json',
          'X-Starrate-Token': 'fake-token-12345',
        },
        failOnStatusCode: false,
      }).then(resp => {
        expect([401, 403]).to.include(resp.status)
      })
    })
  })
})
