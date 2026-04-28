/**
 * StarRate E2E – Guest Gallery: Shares, Passwort, Ablauf, Pick
 */

import { NC_URL, NC_USER, NC_PASS, login, createShare, deleteShare } from './helpers'

describe('Guest Gallery', () => {

  // ── Basis: Share öffnen und bewerten ─────────────────────────────────────

  describe('Basis', () => {
    let token

    before(() => {
      login()
      createShare({ permissions: 'rate', guest_name: 'E2E-Gast' }).then(share => {
        token = share.token
      })
    })

    after(() => deleteShare(token))

    it('öffnet Gast-Galerie ohne Login', () => {
      cy.clearCookies()
      cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)
      cy.get('.sr-grid', { timeout: 15000 }).should('be.visible')
      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 15000 })
        .should('have.length.greaterThan', 0)
    })

    it('Gast kann bewerten (canRate=true)', () => {
      cy.clearCookies()
      cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)
      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 15000 })
        .should('have.length.greaterThan', 0)

      cy.get('.sr-grid').trigger('keydown', { key: 'ArrowRight', bubbles: true })
      cy.get('.sr-grid').trigger('keydown', { key: '4', bubbles: true })
      cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')
    })

    it('Gast-Name wird im Breadcrumb angezeigt', () => {
      cy.clearCookies()
      cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)
      cy.get('.sr-grid', { timeout: 15000 }).should('be.visible')
      cy.get('.sr-breadcrumb__guest-label').should('contain', 'E2E-Gast')
    })
  })

  // ── View-Only: Gast kann nicht bewerten ────────────────────────────────

  describe('View-Only', () => {
    let token

    before(() => {
      login()
      createShare({ permissions: 'view', guest_name: 'Nur-Ansehen' }).then(share => {
        token = share.token
      })
    })

    after(() => deleteShare(token))

    it('zeigt Bilder an', () => {
      cy.clearCookies()
      cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)
      cy.get('.sr-grid', { timeout: 15000 }).should('be.visible')
      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)')
        .should('have.length.greaterThan', 0)
    })

    it('Rating-Taste hat keinen Effekt (kein Toast)', () => {
      cy.clearCookies()
      cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)
      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 15000 })
        .should('have.length.greaterThan', 0)

      cy.get('.sr-grid').trigger('keydown', { key: 'ArrowRight', bubbles: true })
      cy.get('.sr-grid').trigger('keydown', { key: '5', bubbles: true })

      // Kein Success-Toast
      cy.wait(1500)
      cy.get('.sr-toast--success').should('not.exist')
    })

    it('API gibt 403 bei Rate-Versuch', () => {
      cy.request({
        method: 'POST',
        url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/rate`,
        body: { file_id: 99999, rating: 5, guest_name: 'Hacker' },
        headers: { 'Content-Type': 'application/json' },
        failOnStatusCode: false,
      }).then(resp => {
        expect(resp.status).to.eq(403)
      })
    })
  })

  // ── Passwort-geschützter Share ──────────────────────────────────────────

  describe('Passwort', () => {
    let token

    before(() => {
      login()
      createShare({
        permissions: 'rate',
        guest_name: 'PW-Gast',
        password: 'geheim123',
      }).then(share => {
        token = share.token
      })
    })

    after(() => deleteShare(token))

    it('zeigt Passwort-Dialog statt Galerie', () => {
      cy.clearCookies()
      cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)

      // Passwort-Dialog sichtbar
      cy.get('.sr-guest-pw__dialog', { timeout: 10000 }).should('be.visible')
      cy.get('.sr-grid').should('not.exist')
    })

    it('falsches Passwort → Fehlermeldung', () => {
      cy.clearCookies()
      cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)
      cy.get('.sr-guest-pw__dialog', { timeout: 10000 }).should('be.visible')

      cy.get('.sr-guest-pw__input').type('falsch')
      cy.get('.sr-guest-pw__btn').click()
      cy.get('.sr-guest-pw__error', { timeout: 5000 }).should('be.visible')
    })

    it('richtiges Passwort → Galerie öffnet', () => {
      cy.clearCookies()
      cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)
      cy.get('.sr-guest-pw__dialog', { timeout: 10000 }).should('be.visible')

      cy.get('.sr-guest-pw__input').type('geheim123')
      cy.get('.sr-guest-pw__btn').click()

      cy.get('.sr-grid', { timeout: 15000 }).should('be.visible')
      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)')
        .should('have.length.greaterThan', 0)
    })
  })

  // ── Ungültiger / abgelaufener Share ────────────────────────────────────

  describe('Ungültige Shares', () => {

    it('ungültiges Token → Ablauf-Seite', () => {
      cy.clearCookies()
      cy.visit(`${NC_URL}/index.php/apps/starrate/guest/INVALID_TOKEN_123`, {
        failOnStatusCode: false,
      })
      // share_expired Template wird gerendert
      cy.get('body').should('not.contain', '.sr-grid')
    })

    describe('abgelaufener Share', () => {
      let token

      before(() => {
        login()
        // expires_at 2s in die Zukunft + 3s warten = real abgelaufener Share.
        // Backend lehnt PUT mit expires_at in der Vergangenheit ab (Validation),
        // daher der Future-then-wait-Ansatz statt Backdating.
        createShare({
          permissions: 'rate',
          guest_name: 'Abgelaufen',
          expires_at: Math.floor(Date.now() / 1000) + 2,
        }).then(share => {
          token = share.token
          cy.wait(3000)
        })
      })

      after(() => deleteShare(token))

      it('abgelaufener Share → Ablauf-Seite', () => {
        cy.clearCookies()
        cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`, {
          failOnStatusCode: false,
        })
        cy.get('.sr-grid').should('not.exist')
      })
    })

    describe('deaktivierter Share', () => {
      let token

      before(() => {
        login()
        createShare({ permissions: 'rate', guest_name: 'Deaktiviert' }).then(share => {
          token = share.token
          // Deaktivieren
          cy.request({
            method: 'PUT',
            url: `${NC_URL}/index.php/apps/starrate/api/share/${share.token}`,
            body: { active: false },
            headers: { 'Content-Type': 'application/json', 'OCS-APIREQUEST': 'true' },
            auth: { user: NC_USER, pass: NC_PASS },
          })
        })
      })

      after(() => deleteShare(token))

      it('deaktivierter Share → Ablauf-Seite', () => {
        cy.clearCookies()
        cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`, {
          failOnStatusCode: false,
        })
        cy.get('.sr-grid').should('not.exist')
      })
    })
  })

  // ── Share mit allow_pick ───────────────────────────────────────────────

  describe('Pick im Guest-Modus', () => {
    let token

    before(() => {
      login()
      createShare({
        permissions: 'rate',
        guest_name: 'Pick-Gast',
        allow_pick: true,
      }).then(share => {
        token = share.token
      })
    })

    after(() => deleteShare(token))

    it('Pick-UI ist sichtbar wenn allow_pick=true', () => {
      cy.clearCookies()
      cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)
      cy.get('.sr-grid', { timeout: 15000 }).should('be.visible')
      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 15000 })
        .should('have.length.greaterThan', 0)

      // Pick-Filter-Buttons sollten sichtbar sein
      cy.get('.sr-filterbar__pill--pick').should('exist')
    })

    it('Gast kann Pick per API setzen', () => {
      // Bild-ID holen
      cy.request({
        url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/images`,
        headers: { 'Content-Type': 'application/json' },
      }).then(resp => {
        const fileId = resp.body.images[0].id
        cy.request({
          method: 'POST',
          url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/rate`,
          body: { file_id: fileId, pick: 'pick', guest_name: 'Pick-Gast' },
          headers: { 'Content-Type': 'application/json' },
        }).then(rateResp => {
          expect(rateResp.status).to.eq(200)
        })
      })
    })
  })

  // ── Alle Ratings + Farben (NC 32 Regression: TagCreationForbiddenException) ─

  describe('Alle Ratings und Farben via Gast-API', () => {
    let token
    let fileId

    before(() => {
      login()
      createShare({ permissions: 'rate', guest_name: 'Klaviertest', allow_pick: true }).then(share => {
        token = share.token
        cy.request({
          url: `${NC_URL}/index.php/apps/starrate/api/guest/${share.token}/images`,
        }).then(resp => {
          fileId = resp.body.images[0].id
        })
      })
    })

    after(() => deleteShare(token))

    // Ratings 1–5 einzeln setzen — stellt sicher, dass auch Tags die noch
    // nicht in der DB existieren (fresh tags) korrekt angelegt werden (NC 32 Fix)
    ;[1, 2, 3, 4, 5].forEach(stars => {
      it(`Rating ${stars} setzt sich korrekt`, () => {
        cy.request({
          method: 'POST',
          url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/rate`,
          body: { file_id: fileId, rating: stars, guest_name: 'Klaviertest' },
          headers: { 'Content-Type': 'application/json' },
        }).then(r => {
          expect(r.status).to.eq(200)
          expect(r.body.rating).to.eq(stars)
        })
      })
    })

    // Alle Farb-Labels setzen — API akzeptiert Groß- und Kleinschreibung,
    // gibt immer kanonische Form zurück (Red, Yellow, ...)
    ;['red', 'Yellow', 'green', 'BLUE', 'Purple'].forEach(color => {
      const canonical = color.charAt(0).toUpperCase() + color.slice(1).toLowerCase()
      it(`Farbe "${color}" → ${canonical}`, () => {
        cy.request({
          method: 'POST',
          url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/rate`,
          body: { file_id: fileId, color, guest_name: 'Klaviertest' },
          headers: { 'Content-Type': 'application/json' },
        }).then(r => {
          expect(r.status).to.eq(200)
          expect(r.body.color).to.eq(canonical)
        })
      })
    })

    it('Bewertung zurücksetzen (Rating 0, Farbe null)', () => {
      cy.request({
        method: 'POST',
        url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/rate`,
        body: { file_id: fileId, rating: 0, color: null, guest_name: 'Klaviertest' },
        headers: { 'Content-Type': 'application/json' },
      }).then(r => {
        expect(r.status).to.eq(200)
        expect(r.body.rating).to.eq(0)
        expect(r.body.color).to.be.null
      })
    })
  })

  // ── Share ohne allow_pick: Pick wird serverseitig ignoriert ─────────────

  describe('Pick blockiert ohne allow_pick', () => {
    let token

    before(() => {
      login()
      createShare({
        permissions: 'rate',
        guest_name: 'No-Pick-Gast',
        allow_pick: false,
      }).then(share => {
        token = share.token
      })
    })

    after(() => deleteShare(token))

    it('Pick-UI ist nicht sichtbar wenn allow_pick=false', () => {
      cy.clearCookies()
      cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)
      cy.get('.sr-grid', { timeout: 15000 }).should('be.visible')
      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 15000 })
        .should('have.length.greaterThan', 0)

      // Keine Pick-Filter-Buttons
      cy.get('.sr-filterbar__pill--pick').should('not.exist')
    })

    it('API ignoriert Pick-Parameter stillschweigend', () => {
      cy.request({
        url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/images`,
      }).then(resp => {
        const fileId = resp.body.images[0].id
        // Pick mitschicken → wird ignoriert, kein Fehler
        cy.request({
          method: 'POST',
          url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/rate`,
          body: { file_id: fileId, rating: 3, pick: 'pick', guest_name: 'Sneaky' },
          headers: { 'Content-Type': 'application/json' },
        }).then(rateResp => {
          expect(rateResp.status).to.eq(200)
          // Rating wurde gesetzt, aber Pick ignoriert
          expect(rateResp.body.rating).to.eq(3)
          expect(rateResp.body.pick).to.be.null
        })
      })
    })
  })
})
